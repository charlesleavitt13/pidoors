import { useState, useRef } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { Upload, FileText, Loader2, CheckCircle, AlertTriangle, Download } from 'lucide-react';
import { importCards } from '../api/cards';
import { getGroups } from '../api/groups';
import { getSchedules } from '../api/schedules';
import toast from 'react-hot-toast';

const SAMPLE_CSV = `card_id,user_id,facility,firstname,lastname,doors,active,email,department
a1b2c3d4,EMP001,123,John,Smith,front-door,1,john@example.com,Engineering
b2c3d4e5,EMP002,123,Jane,Doe,front-door,1,jane@example.com,Marketing
`;

function parseCsvPreview(text: string): string[][] {
  const rows: string[][] = [];
  let cell = '';
  let row: string[] = [];
  let inQuotes = false;
  for (let i = 0; i < text.length; i++) {
    const ch = text[i];
    if (inQuotes) {
      if (ch === '"') {
        if (text[i + 1] === '"') {
          cell += '"';
          i++;
        } else {
          inQuotes = false;
        }
      } else {
        cell += ch;
      }
    } else if (ch === '"') {
      inQuotes = true;
    } else if (ch === ',') {
      row.push(cell.trim());
      cell = '';
    } else if (ch === '\n' || ch === '\r') {
      if (ch === '\r' && text[i + 1] === '\n') i++;
      row.push(cell.trim());
      if (row.some((c) => c !== '')) rows.push(row);
      row = [];
      cell = '';
      if (rows.length >= 6) break;
    } else {
      cell += ch;
    }
  }
  if (cell !== '' || row.length > 0) {
    row.push(cell.trim());
    if (row.some((c) => c !== '')) rows.push(row);
  }
  return rows.slice(0, 6);
}

export function ImportCardsPage() {
  const queryClient = useQueryClient();
  const fileRef = useRef<HTMLInputElement>(null);
  const [selectedFile, setSelectedFile] = useState<File | null>(null);
  const [preview, setPreview] = useState<string[][]>([]);
  const [defaultGroup, setDefaultGroup] = useState<number | ''>('');
  const [defaultSchedule, setDefaultSchedule] = useState<number | ''>('');
  const [skipDuplicates, setSkipDuplicates] = useState(true);
  const [result, setResult] = useState<{
    imported: number;
    skipped: number;
    errors?: string[];
    warnings?: string[];
  } | null>(null);

  const { data: groups = [] } = useQuery({ queryKey: ['groups'], queryFn: getGroups });
  const { data: schedules = [] } = useQuery({ queryKey: ['schedules'], queryFn: getSchedules });

  const handleFileSelect = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;
    setSelectedFile(file);
    setResult(null);

    const reader = new FileReader();
    reader.onload = (ev) => {
      const text = ev.target?.result as string;
      setPreview(parseCsvPreview(text.replace(/^\uFEFF/, '')));
    };
    reader.readAsText(file);
  };

  const importMutation = useMutation({
    mutationFn: (file: File) =>
      importCards(file, {
        default_group: defaultGroup === '' ? null : defaultGroup,
        default_schedule: defaultSchedule === '' ? null : defaultSchedule,
        skip_duplicates: skipDuplicates,
      }),
    onSuccess: (data) => {
      setResult({
        imported: (data as { imported?: number }).imported ?? 0,
        skipped: (data as { skipped?: number }).skipped ?? 0,
        errors: (data as { errors?: string[] }).errors,
        warnings: (data as { warnings?: string[] }).warnings,
      });
      queryClient.invalidateQueries({ queryKey: ['cards'] });
      toast.success(`Imported ${(data as { imported?: number }).imported ?? 0} cards`);
    },
    onError: (err: Error) => toast.error(err.message),
  });

  const handleImport = () => {
    if (!selectedFile) return;
    importMutation.mutate(selectedFile);
  };

  const downloadSample = () => {
    const blob = new Blob([SAMPLE_CSV], { type: 'text/csv;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'sample_cards.csv';
    a.click();
    URL.revokeObjectURL(url);
  };

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-bold text-slate-900 dark:text-white">Import Cards</h1>
        <Link to="/cards" className="btn btn-outline">
          Back to Cards
        </Link>
      </div>

      <div className="grid gap-6 lg:grid-cols-3">
        <div className="card p-6 lg:col-span-2">
          <p className="text-sm text-slate-600 dark:text-slate-400 mb-4">
            Required columns: <code className="rounded bg-slate-100 px-1 py-0.5 dark:bg-slate-700">card_id</code>,{' '}
            <code className="rounded bg-slate-100 px-1 py-0.5 dark:bg-slate-700">user_id</code>.
            For a card that actually grants access, also include{' '}
            <code className="rounded bg-slate-100 px-1 py-0.5 dark:bg-slate-700">facility</code> and{' '}
            <code className="rounded bg-slate-100 px-1 py-0.5 dark:bg-slate-700">doors</code> (or assign a default access group whose doors will be copied).
          </p>

          <div
            onClick={() => fileRef.current?.click()}
            className="flex cursor-pointer flex-col items-center justify-center rounded-lg border-2 border-dashed border-slate-300 p-8 transition-colors hover:border-primary-500 dark:border-slate-600"
          >
            <Upload className="h-10 w-10 text-slate-400" />
            <p className="mt-2 text-sm font-medium text-slate-700 dark:text-slate-300">
              {selectedFile ? selectedFile.name : 'Click to select CSV file'}
            </p>
            {selectedFile && (
              <p className="text-xs text-slate-500">
                {(selectedFile.size / 1024).toFixed(1)} KB
              </p>
            )}
            <input
              ref={fileRef}
              type="file"
              accept=".csv"
              className="hidden"
              onChange={handleFileSelect}
            />
          </div>

          <div className="mt-4 grid gap-4 sm:grid-cols-2">
            <div>
              <label className="label" htmlFor="default_group">Default Access Group</label>
              <select
                id="default_group"
                className="input"
                value={defaultGroup}
                onChange={(e) => setDefaultGroup(e.target.value ? parseInt(e.target.value, 10) : '')}
              >
                <option value="">None</option>
                {groups.map((g) => (
                  <option key={g.id} value={g.id}>{g.name}</option>
                ))}
              </select>
              <p className="mt-1 text-xs text-slate-500">Applied when group_id is not in the CSV. Group doors are copied onto the card if doors is empty.</p>
            </div>
            <div>
              <label className="label" htmlFor="default_schedule">Default Schedule</label>
              <select
                id="default_schedule"
                className="input"
                value={defaultSchedule}
                onChange={(e) => setDefaultSchedule(e.target.value ? parseInt(e.target.value, 10) : '')}
              >
                <option value="">None (24/7)</option>
                {schedules.map((s) => (
                  <option key={s.id} value={s.id}>{s.name}</option>
                ))}
              </select>
              <p className="mt-1 text-xs text-slate-500">Applied when schedule_id is not in the CSV.</p>
            </div>
          </div>

          <label className="mt-4 flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
            <input
              type="checkbox"
              checked={skipDuplicates}
              onChange={(e) => setSkipDuplicates(e.target.checked)}
              className="h-4 w-4 rounded border-slate-300 text-primary-600 focus:ring-primary-500"
            />
            Skip duplicate card_id / user_id entries
          </label>

          {preview.length > 0 && (
            <div className="mt-4">
              <h3 className="mb-2 text-sm font-medium text-slate-700 dark:text-slate-300">
                Preview (first {Math.max(0, preview.length - 1)} rows)
              </h3>
              <div className="overflow-x-auto rounded border border-slate-200 dark:border-slate-700">
                <table className="w-full text-xs">
                  <thead>
                    <tr className="bg-slate-50 dark:bg-slate-700/50">
                      {preview[0]?.map((h, i) => (
                        <th key={i} className="px-3 py-2 text-left font-medium text-slate-500">
                          {h}
                        </th>
                      ))}
                    </tr>
                  </thead>
                  <tbody>
                    {preview.slice(1).map((row, ri) => (
                      <tr key={ri} className="border-t border-slate-100 dark:border-slate-700/50">
                        {row.map((cell, ci) => (
                          <td key={ci} className="px-3 py-1.5 text-slate-700 dark:text-slate-300">
                            {cell}
                          </td>
                        ))}
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          )}

          {selectedFile && !result && (
            <button
              onClick={handleImport}
              disabled={importMutation.isPending}
              className="btn btn-primary mt-4"
            >
              {importMutation.isPending ? (
                <Loader2 className="h-4 w-4 animate-spin" />
              ) : (
                <FileText className="h-4 w-4" />
              )}
              {importMutation.isPending ? 'Importing...' : 'Import Cards'}
            </button>
          )}

          {result && (
            <div className="mt-4 space-y-2">
              <div className="flex items-center gap-2 text-emerald-600 dark:text-emerald-400">
                <CheckCircle className="h-5 w-5" />
                <span className="font-medium">{result.imported} cards imported</span>
              </div>
              {result.skipped > 0 && (
                <div className="flex items-center gap-2 text-amber-600 dark:text-amber-400">
                  <AlertTriangle className="h-5 w-5" />
                  <span>{result.skipped} rows skipped (duplicates or errors)</span>
                </div>
              )}
              {result.warnings && result.warnings.length > 0 && (
                <div className="rounded bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-900/20 dark:text-amber-400">
                  {result.warnings.map((w, i) => <p key={i}>{w}</p>)}
                </div>
              )}
              {result.errors && result.errors.length > 0 && (
                <div className="rounded bg-red-50 p-3 text-sm text-red-700 dark:bg-red-900/20 dark:text-red-400">
                  {result.errors.map((e, i) => <p key={i}>{e}</p>)}
                </div>
              )}
              <button
                onClick={() => {
                  setSelectedFile(null);
                  setPreview([]);
                  setResult(null);
                  if (fileRef.current) fileRef.current.value = '';
                }}
                className="btn btn-secondary mt-2"
              >
                Import Another
              </button>
            </div>
          )}
        </div>

        <div className="space-y-4">
          <div className="card p-5">
            <h2 className="mb-3 text-sm font-semibold text-slate-900 dark:text-white">CSV columns</h2>
            <p className="text-xs font-medium text-slate-600 dark:text-slate-400">Required</p>
            <ul className="mb-3 mt-1 list-disc space-y-0.5 pl-4 text-xs text-slate-600 dark:text-slate-400">
              <li><code>card_id</code> — Wiegand card ID (also used as keypad PIN)</li>
              <li><code>user_id</code> — unique cardholder identifier</li>
            </ul>
            <p className="text-xs font-medium text-slate-600 dark:text-slate-400">Needed to grant access</p>
            <ul className="mb-3 mt-1 list-disc space-y-0.5 pl-4 text-xs text-slate-600 dark:text-slate-400">
              <li><code>facility</code> — must match the reader facility code</li>
              <li><code>doors</code> — comma-separated door names, or <code>*</code> for all</li>
            </ul>
            <p className="text-xs font-medium text-slate-600 dark:text-slate-400">Optional</p>
            <ul className="mt-1 list-disc space-y-0.5 pl-4 text-xs text-slate-600 dark:text-slate-400">
              <li><code>firstname</code>, <code>lastname</code>, <code>email</code>, <code>phone</code></li>
              <li><code>department</code>, <code>employee_id</code>, <code>company</code>, <code>title</code>, <code>notes</code></li>
              <li><code>active</code> — 1/0 (default 1)</li>
              <li><code>group_id</code> — access group ID (copies group doors if <code>doors</code> is empty)</li>
              <li><code>schedule_id</code>, <code>valid_from</code>, <code>valid_until</code></li>
              <li><code>daily_scan_limit</code>, <code>master</code> (1/yes/true)</li>
            </ul>
            <p className="mt-3 text-xs text-slate-500">
              Keypad PIN is <code>card_id</code>. A <code>pin_code</code> column is ignored.
            </p>
          </div>

          <div className="card p-5">
            <h2 className="mb-2 text-sm font-semibold text-slate-900 dark:text-white">Sample CSV</h2>
            <pre className="overflow-x-auto rounded bg-slate-50 p-2 text-[11px] text-slate-700 dark:bg-slate-900 dark:text-slate-300">{SAMPLE_CSV}</pre>
            <button type="button" onClick={downloadSample} className="btn btn-outline btn-sm mt-3">
              <Download className="h-3.5 w-3.5" />
              Download sample
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}
