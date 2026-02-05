export type MigrationStatus = 'pending' | 'in_progress' | 'success' | 'error' | 'completed';

export const statusConfig: Record<MigrationStatus, { label: string; color: string; bgColor: string }> = {
  pending: {
    label: 'Ожидает',
    color: '#64748b',
    bgColor: '#f1f5f9',
  },
  in_progress: {
    label: 'Выполняется',
    color: '#3b82f6',
    bgColor: '#dbeafe',
  },
  success: {
    label: 'Успешно',
    color: '#10b981',
    bgColor: '#d1fae5',
  },
  completed: {
    label: 'Завершено',
    color: '#10b981',
    bgColor: '#d1fae5',
  },
  error: {
    label: 'Ошибка',
    color: '#ef4444',
    bgColor: '#fee2e2',
  },
};

export function getStatusConfig(status: MigrationStatus) {
  return statusConfig[status] || statusConfig.pending;
}
