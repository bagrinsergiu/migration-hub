import { useState, useEffect } from 'react';
import { api } from '../api/client';
import './MigrationServerStatus.css';

interface HandshakeStatus {
  success: boolean;
  message?: string;
  migration_server?: { service: string; server_id: string; client_ip?: string; timestamp?: string };
  handshake_with_dashboard: 'ok' | 'fail';
  handshake_error?: string;
  http_code?: number;
}

export default function MigrationServerStatus() {
  const [handshake, setHandshake] = useState<HandshakeStatus | null>(null);
  const [loading, setLoading] = useState(true);

  const checkStatus = async () => {
    setLoading(true);
    try {
      const response = await api.getMigrationServerHandshake();
      const data = response as unknown as HandshakeStatus;
      if (data && typeof data.success !== 'undefined') {
        setHandshake({
          success: data.success,
          message: data.message,
          migration_server: data.migration_server,
          handshake_with_dashboard: data.handshake_with_dashboard ?? 'fail',
          handshake_error: data.handshake_error,
          http_code: data.http_code
        });
      } else {
        setHandshake({
          success: false,
          handshake_with_dashboard: 'fail',
          message: 'Неверный ответ'
        });
      }
    } catch (err: any) {
      // При 503 бэкенд возвращает тело с handshake_error — используем его
      const body = err?.response?.data;
      if (body && typeof body.handshake_with_dashboard !== 'undefined') {
        setHandshake({
          success: body.success ?? false,
          message: body.message,
          migration_server: body.migration_server,
          handshake_with_dashboard: body.handshake_with_dashboard ?? 'fail',
          handshake_error: body.handshake_error,
          http_code: body.http_code ?? err?.response?.status
        });
        // Не логируем в консоль при каждом 503 — причина видна в UI (tooltip и подпись)
      } else {
        setHandshake({
          success: false,
          handshake_with_dashboard: 'fail',
          message: body?.message || err?.message || 'Сервер недоступен'
        });
      }
    } finally {
      setLoading(false);
    }
  };

  // При неуспешном рукопожатии опрашиваем реже (90 с), чтобы не спамить при сетевых сбоях
  const isOk = handshake?.success && handshake?.handshake_with_dashboard === 'ok';
  const pollIntervalMs = isOk ? 30000 : 90000;

  useEffect(() => {
    // Небольшая задержка перед первой проверкой, чтобы не засорять консоль 503 сразу при загрузке страницы
    const t = setTimeout(() => checkStatus(), 2000);
    const interval = setInterval(() => checkStatus(), pollIntervalMs);
    return () => {
      clearTimeout(t);
      clearInterval(interval);
    };
  }, [pollIntervalMs]);

  if (loading && !handshake) {
    return (
      <div className="migration-server-status loading">
        <span className="status-indicator">⏳</span>
        <span className="status-text">Проверка...</span>
      </div>
    );
  }

  const statusIcon = isOk ? '✅' : '❌';
  const statusClass = isOk ? 'available' : 'unavailable';
  const titleParts = [
    handshake?.migration_server?.server_id && `Сервер: ${handshake.migration_server.server_id}`,
    handshake?.migration_server?.client_ip && `IP: ${handshake.migration_server.client_ip}`,
    isOk ? 'Рукопожатие: ок' : (handshake?.handshake_error || handshake?.message || 'Рукопожатие не прошло')
  ].filter(Boolean);
  const title = titleParts.length ? titleParts.join(' · ') : 'Статус сервера миграции';

  const errorText = !isOk && (handshake?.handshake_error || handshake?.message) ? (handshake.handshake_error || handshake.message) : null;

  return (
    <div
      className={`migration-server-status ${statusClass}`}
      title={title}
      onClick={checkStatus}
      style={{ cursor: 'pointer' }}
    >
      <span className="status-indicator">{statusIcon}</span>
      <div className="migration-server-status__labels">
        <span className="status-text">Сервер миграции</span>
        {errorText && <span className="migration-server-status__error" title={errorText}>{errorText}</span>}
      </div>
    </div>
  );
}
