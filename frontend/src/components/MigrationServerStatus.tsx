import { useState, useEffect } from 'react';
import { api } from '../api/client';
import './MigrationServerStatus.css';

interface ServerStatus {
  available: boolean;
  message: string;
  http_code: number | null;
  timestamp?: string;
}

export default function MigrationServerStatus() {
  const [status, setStatus] = useState<ServerStatus | null>(null);
  const [loading, setLoading] = useState(true);

  const checkStatus = async () => {
    try {
      const response = await api.checkMigrationServerHealth();
      if (response.success !== undefined && response.data) {
        setStatus({
          available: response.data.available ?? response.success,
          message: response.data.message || 'Неизвестный статус',
          http_code: response.data.http_code ?? null,
          timestamp: response.data.timestamp
        });
      } else {
        setStatus({
          available: false,
          message: 'Ошибка получения статуса',
          http_code: null
        });
      }
    } catch (err: any) {
      setStatus({
        available: false,
        message: 'Сервер недоступен',
        http_code: null
      });
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    // Проверяем статус при монтировании
    checkStatus();

    // Обновляем статус каждые 30 секунд
    const interval = setInterval(() => {
      checkStatus();
    }, 30000);

    return () => clearInterval(interval);
  }, []);

  if (loading && !status) {
    return (
      <div className="migration-server-status loading">
        <span className="status-indicator">⏳</span>
        <span className="status-text">Проверка...</span>
      </div>
    );
  }

  const isAvailable = status?.available ?? false;
  const statusIcon = isAvailable ? '✅' : '❌';
  const statusClass = isAvailable ? 'available' : 'unavailable';

  return (
    <div 
      className={`migration-server-status ${statusClass}`}
      title={status?.message || 'Статус сервера миграции'}
      onClick={checkStatus}
      style={{ cursor: 'pointer' }}
    >
      <span className="status-indicator">{statusIcon}</span>
      <span className="status-text">Сервер миграции</span>
    </div>
  );
}
