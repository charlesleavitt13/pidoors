<?php
/**
 * Import Cards from CSV
 * PiDoors Access Control System
 */
$title = 'Import Cards';
require_once './includes/header.php';

require_login($config);
require_admin($config);

$error_message = '';
$success_message = '';
$import_results = [];

// Get groups and schedules for mapping
try {
    $groups = $pdo_access->query("SELECT id, name, doors FROM access_groups ORDER BY name")->fetchAll();
    $schedules = $pdo_access->query("SELECT id, name FROM access_schedules ORDER BY name")->fetchAll();
} catch (PDOException $e) {
    $groups = [];
    $schedules = [];
}

$valid_groups = [];
$group_doors_map = [];
foreach ($groups as $g) {
    $gid = (int)$g['id'];
    $valid_groups[$gid] = true;
    $group_doors_map[$gid] = $g['doors'] ?? '';
}
$valid_schedules = [];
foreach ($schedules as $s) {
    $valid_schedules[(int)$s['id']] = true;
}

function import_copy_group_doors(string $doors, ?int $group_id, array $group_doors_map): string {
    $doors = trim($doors);
    if ($doors !== '') {
        return $doors;
    }
    if ($group_id === null || !isset($group_doors_map[$group_id])) {
        return '';
    }
    $decoded = json_decode((string)$group_doors_map[$group_id], true);
    if (!is_array($decoded) || $decoded === []) {
        return '';
    }
    $names = [];
    foreach ($decoded as $name) {
        $name = trim((string)$name);
        if ($name !== '') {
            $names[] = $name;
        }
    }
    return implode(',', $names);
}

function import_parse_active($value): int {
    $v = strtolower(trim((string)$value));
    if ($v === '0' || $v === 'no' || $v === 'false' || $v === 'inactive') {
        return 0;
    }
    return 1;
}

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error_message = 'Invalid security token.';
    } else {
        $file = $_FILES['csv_file'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $error_message = 'File upload failed.';
        } elseif ($file['size'] > 5 * 1024 * 1024) { // 5MB limit
            $error_message = 'File is too large. Maximum size is 5MB.';
        } else {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($file['tmp_name']);
            if (!in_array($mime, ['text/plain', 'text/csv', 'application/csv', 'text/x-csv'])) {
                $error_message = 'Invalid file type. Please upload a CSV file.';
            } else {
                // Process CSV
                $handle = fopen($file['tmp_name'], 'r');
                if ($handle) {
                    $header = fgetcsv($handle);
                    if (!$header) {
                        $error_message = 'CSV file is empty or invalid.';
                    } else {
                        // Normalize headers (strip Excel UTF-8 BOM)
                        if (isset($header[0])) {
                            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
                        }
                        $header = array_map('strtolower', array_map('trim', $header));

                        // Map columns
                        $col_map = [];
                        $required_cols = ['card_id', 'user_id'];
                        $optional_cols = ['facility', 'firstname', 'lastname', 'doors', 'active', 'email', 'phone', 'department', 'employee_id', 'company', 'title', 'notes', 'group_id', 'schedule_id', 'valid_from', 'valid_until', 'daily_scan_limit', 'master'];

                        foreach ($required_cols as $col) {
                            $idx = array_search($col, $header);
                            if ($idx === false) {
                                $error_message = "Missing required column: {$col}";
                                break;
                            }
                            $col_map[$col] = $idx;
                        }

                        if (!$error_message) {
                            foreach ($optional_cols as $col) {
                                $idx = array_search($col, $header);
                                if ($idx !== false) {
                                    $col_map[$col] = $idx;
                                }
                            }

                            $default_group = validate_int($_POST['default_group'] ?? 0) ?: null;
                            $default_schedule = validate_int($_POST['default_schedule'] ?? 0) ?: null;
                            $skip_duplicates = isset($_POST['skip_duplicates']);

                            if ($default_group !== null && !isset($valid_groups[$default_group])) {
                                $error_message = "Unknown default group.";
                            } elseif ($default_schedule !== null && !isset($valid_schedules[$default_schedule])) {
                                $error_message = "Unknown default schedule.";
                            }

                            $imported = 0;
                            $skipped = 0;
                            $errors = 0;
                            $line_num = 1;
                            $header_count = count($header);

                            if (!$error_message) {
                            $pdo_access->beginTransaction();

                            try {
                                $insert_stmt = $pdo_access->prepare("
                                    INSERT INTO cards (card_id, user_id, facility, firstname, lastname, doors, active, email, phone, department, employee_id, company, title, notes, group_id, schedule_id, valid_from, valid_until, daily_scan_limit)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                                ");
                                $dup_stmt = $pdo_access->prepare("SELECT id FROM cards WHERE card_id = ? OR user_id = ?");

                                while (($row = fgetcsv($handle)) !== false) {
                                    $line_num++;

                                    if ($row === [null] || (count($row) === 1 && trim((string)$row[0]) === '')) {
                                        continue;
                                    }
                                    if (count($row) < $header_count) {
                                        $row = array_pad($row, $header_count, '');
                                    }

                                    $card_id = sanitize_string($row[$col_map['card_id']] ?? '');
                                    $user_id = sanitize_string($row[$col_map['user_id']] ?? '');

                                    if (empty($card_id) || empty($user_id)) {
                                        $import_results[] = "Line {$line_num}: Skipped - missing card_id or user_id";
                                        $skipped++;
                                        continue;
                                    }

                                    if ($skip_duplicates) {
                                        $dup_stmt->execute([$card_id, $user_id]);
                                        if ($dup_stmt->fetch()) {
                                            $import_results[] = "Line {$line_num}: Skipped - duplicate card_id or user_id";
                                            $skipped++;
                                            continue;
                                        }
                                    }

                                    $csv_facility = sanitize_string($row[$col_map['facility'] ?? -1] ?? '');
                                    $firstname = sanitize_string($row[$col_map['firstname'] ?? -1] ?? '');
                                    $lastname = sanitize_string($row[$col_map['lastname'] ?? -1] ?? '');
                                    $csv_email = sanitize_string($row[$col_map['email'] ?? -1] ?? '') ?: null;
                                    $csv_phone = sanitize_string($row[$col_map['phone'] ?? -1] ?? '') ?: null;
                                    $csv_department = sanitize_string($row[$col_map['department'] ?? -1] ?? '') ?: null;
                                    $csv_employee_id = sanitize_string($row[$col_map['employee_id'] ?? -1] ?? '') ?: null;
                                    $csv_company = sanitize_string($row[$col_map['company'] ?? -1] ?? '') ?: null;
                                    $csv_title = sanitize_string($row[$col_map['title'] ?? -1] ?? '') ?: null;
                                    $csv_notes = sanitize_string($row[$col_map['notes'] ?? -1] ?? '') ?: null;
                                    $group_id = validate_int($row[$col_map['group_id'] ?? -1] ?? 0) ?: $default_group;
                                    $schedule_id = validate_int($row[$col_map['schedule_id'] ?? -1] ?? 0) ?: $default_schedule;
                                    if ($group_id !== null && !isset($valid_groups[$group_id])) {
                                        $import_results[] = "Line {$line_num}: Skipped - unknown group_id {$group_id}";
                                        $skipped++;
                                        continue;
                                    }
                                    if ($schedule_id !== null && !isset($valid_schedules[$schedule_id])) {
                                        $import_results[] = "Line {$line_num}: Skipped - unknown schedule_id {$schedule_id}";
                                        $skipped++;
                                        continue;
                                    }
                                    $csv_doors = import_copy_group_doors(
                                        sanitize_string($row[$col_map['doors'] ?? -1] ?? ''),
                                        $group_id,
                                        $group_doors_map
                                    );
                                    $csv_active = import_parse_active($row[$col_map['active'] ?? -1] ?? '1');
                                    $valid_from = sanitize_string($row[$col_map['valid_from'] ?? -1] ?? '') ?: null;
                                    $valid_until = sanitize_string($row[$col_map['valid_until'] ?? -1] ?? '') ?: null;
                                    $csv_daily_scan_limit = isset($col_map['daily_scan_limit']) ? (validate_int($row[$col_map['daily_scan_limit']] ?? '', 0, 999) ?: null) : null;
                                    $csv_master = strtolower(trim($row[$col_map['master'] ?? -1] ?? ''));
                                    $is_master = in_array($csv_master, ['1', 'yes', 'true'], true);

                                    if ($csv_facility === '') {
                                        $import_results[] = "Line {$line_num}: Warning - no facility; a scan may not match this card";
                                    }
                                    if ($csv_doors === '') {
                                        $import_results[] = "Line {$line_num}: Warning - no doors assigned; this card cannot open any door";
                                    }

                                    try {
                                        $insert_stmt->execute([
                                            $card_id, $user_id, $csv_facility, $firstname, $lastname,
                                            $csv_doors, $csv_active,
                                            $csv_email, $csv_phone, $csv_department, $csv_employee_id,
                                            $csv_company, $csv_title, $csv_notes,
                                            $group_id, $schedule_id, $valid_from, $valid_until,
                                            $csv_daily_scan_limit
                                        ]);

                                        if ($is_master) {
                                            $desc = trim($firstname . ' ' . $lastname) ?: 'Card ' . $card_id;
                                            $master_stmt = $pdo_access->prepare("INSERT INTO master_cards (card_id, user_id, facility, description, active) VALUES (?, ?, ?, ?, 1)");
                                            $master_stmt->execute([$card_id, $user_id, $csv_facility, $desc]);
                                        }

                                        $imported++;
                                    } catch (PDOException $e) {
                                        if ($e->getCode() == 23000) {
                                            $import_results[] = "Line {$line_num}: Failed - duplicate entry";
                                            $skipped++;
                                        } else {
                                            $import_results[] = "Line {$line_num}: Error - " . $e->getMessage();
                                            $errors++;
                                        }
                                    }
                                }

                                $pdo_access->commit();
                                $success_message = "Import complete: {$imported} imported, {$skipped} skipped, {$errors} errors.";

                                log_security_event($pdo, 'cards_imported', $_SESSION['user_id'] ?? null, "{$imported} cards imported from CSV");

                            } catch (Exception $e) {
                                $pdo_access->rollBack();
                                $error_message = 'Import failed: ' . $e->getMessage();
                            }
                            }
                        }
                    }
                    fclose($handle);
                }
            }
        }
    }
}
?>

<div class="row">
    <div class="col-lg-8">
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">Import Cards from CSV</h5>
            </div>
            <div class="card-body">
                <?php if ($error_message): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
                <?php endif; ?>
                <?php if ($success_message): ?>
                    <div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div>
                <?php endif; ?>

                <form method="post" enctype="multipart/form-data">
                    <?php echo csrf_field(); ?>

                    <div class="mb-3">
                        <label for="csv_file" class="form-label">CSV File <span class="text-danger">*</span></label>
                        <input type="file" class="form-control" id="csv_file" name="csv_file" accept=".csv" required>
                        <div class="form-text">Maximum file size: 5MB</div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="default_group" class="form-label">Default Access Group</label>
                            <select class="form-select" id="default_group" name="default_group">
                                <option value="">None</option>
                                <?php foreach ($groups as $group): ?>
                                    <option value="<?php echo $group['id']; ?>">
                                        <?php echo htmlspecialchars($group['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Applied when group_id is not in CSV</div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="default_schedule" class="form-label">Default Schedule</label>
                            <select class="form-select" id="default_schedule" name="default_schedule">
                                <option value="">None (24/7)</option>
                                <?php foreach ($schedules as $schedule): ?>
                                    <option value="<?php echo $schedule['id']; ?>">
                                        <?php echo htmlspecialchars($schedule['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Applied when schedule_id is not in CSV</div>
                        </div>
                    </div>

                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" id="skip_duplicates" name="skip_duplicates" checked>
                        <label class="form-check-label" for="skip_duplicates">Skip duplicate card_id/user_id entries</label>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="me-1"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
                        Import Cards
                    </button>
                </form>

                <?php if (!empty($import_results)): ?>
                    <hr>
                    <h6>Import Details:</h6>
                    <div class="bg-light p-3" style="max-height: 300px; overflow-y: auto;">
                        <?php foreach ($import_results as $result): ?>
                            <div class="small"><?php echo htmlspecialchars($result); ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card shadow-sm mb-4">
            <div class="card-header">
                <h6 class="mb-0">CSV Format Requirements</h6>
            </div>
            <div class="card-body">
                <p class="small"><strong>Required columns:</strong></p>
                <ul class="small">
                    <li><code>card_id</code> - Wiegand card ID (also used as keypad PIN)</li>
                    <li><code>user_id</code> - Unique user identifier</li>
                </ul>

                <p class="small"><strong>Needed to grant access:</strong></p>
                <ul class="small">
                    <li><code>facility</code> - Must match the reader facility code</li>
                    <li><code>doors</code> - Comma-separated door names, or <code>*</code> for all. If empty and a group is assigned, that group's doors are copied.</li>
                </ul>

                <p class="small"><strong>Optional columns:</strong></p>
                <ul class="small">
                    <li><code>firstname</code> - First name</li>
                    <li><code>lastname</code> - Last name</li>
                    <li><code>active</code> - 1/0 (default 1)</li>
                    <li><code>email</code> - Email address</li>
                    <li><code>phone</code> - Phone number</li>
                    <li><code>department</code> - Department</li>
                    <li><code>employee_id</code> - Employee ID</li>
                    <li><code>company</code> - Company name</li>
                    <li><code>title</code> - Job title</li>
                    <li><code>notes</code> - Notes</li>
                    <li><code>group_id</code> - Access group ID</li>
                    <li><code>schedule_id</code> - Schedule ID</li>
                    <li><code>valid_from</code> - Start date (YYYY-MM-DD)</li>
                    <li><code>valid_until</code> - End date (YYYY-MM-DD)</li>
                    <li><code>daily_scan_limit</code> - Max scans per day (0 = unlimited)</li>
                    <li><code>master</code> - Master card (1/yes/true)</li>
                </ul>
                <p class="small text-muted mb-0">Keypad PIN is <code>card_id</code>. A <code>pin_code</code> column is ignored.</p>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header">
                <h6 class="mb-0">Sample CSV</h6>
            </div>
            <div class="card-body">
                <pre class="small bg-light p-2 mb-0">card_id,user_id,facility,firstname,lastname,doors,active
a1b2c3d4,U001,123,John,Smith,front-door,1
b2c3d4e5,U002,123,Jane,Doe,front-door,1</pre>
                <a href="data:text/csv;charset=utf-8,card_id,user_id,facility,firstname,lastname,doors,active%0Aa1b2c3d4,U001,123,John,Smith,front-door,1%0Ab2c3d4e5,U002,123,Jane,Doe,front-door,1"
                   download="sample_cards.csv" class="btn btn-sm btn-outline-secondary mt-2">Download Sample</a>
            </div>
        </div>
    </div>
</div>

<?php require_once $config['apppath'] . 'includes/footer.php'; ?>
