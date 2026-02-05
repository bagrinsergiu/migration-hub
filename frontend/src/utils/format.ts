import { format, formatDistanceToNow } from 'date-fns';

export function formatDate(dateString: string): string {
  try {
    return format(new Date(dateString), 'dd.MM.yyyy HH:mm');
  } catch {
    return dateString;
  }
}

export function formatRelativeTime(dateString: string): string {
  try {
    return formatDistanceToNow(new Date(dateString), { addSuffix: true });
  } catch {
    return dateString;
  }
}

export function formatUUID(uuid: string): string {
  if (!uuid) return '-';
  return uuid.length > 36 ? `${uuid.substring(0, 8)}...${uuid.substring(uuid.length - 8)}` : uuid;
}
