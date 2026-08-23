import { api } from './client';
import type { Card, ApiResponse } from '../types';

export async function getCards(): Promise<Card[]> {
  const data = await api<{ cards: Card[] }>('cards');
  return data.cards;
}

export async function getCard(id: string): Promise<Card> {
  const data = await api<{ card: Card }>(`cards/${encodeURIComponent(id)}`);
  return data.card;
}

export async function createCard(card: Partial<Card>): Promise<ApiResponse & { card_id?: string }> {
  return api('cards', {
    method: 'POST',
    body: JSON.stringify(card),
  });
}

export async function updateCard(id: string, card: Partial<Card>): Promise<ApiResponse> {
  return api(`cards/${encodeURIComponent(id)}`, {
    method: 'PUT',
    body: JSON.stringify(card),
  });
}

export async function deleteCard(id: string): Promise<ApiResponse> {
  return api(`cards/${encodeURIComponent(id)}`, { method: 'DELETE' });
}

export async function importCards(
  file: File,
  options?: {
    default_group?: number | null;
    default_schedule?: number | null;
    skip_duplicates?: boolean;
  }
): Promise<ApiResponse & { imported?: number; skipped?: number; errors?: string[]; warnings?: string[] }> {
  const formData = new FormData();
  formData.append('file', file);
  if (options?.default_group) {
    formData.append('default_group', String(options.default_group));
  }
  if (options?.default_schedule) {
    formData.append('default_schedule', String(options.default_schedule));
  }
  formData.append('skip_duplicates', options?.skip_duplicates === false ? '0' : '1');
  return api('cards/import', {
    method: 'POST',
    body: formData,
  });
}

export function getExportUrl(): string {
  return '/api/cards/export';
}
