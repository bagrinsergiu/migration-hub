import React, { useState, useEffect, useCallback } from 'react';
import { api, Wave, SheetInfo } from '../../api/client';
import './GoogleSheets.css';

interface GoogleSheetsLinkWaveProps {
  onLinked?: () => void;
}

export default function GoogleSheetsLinkWave({ onLinked }: GoogleSheetsLinkWaveProps) {
  const [spreadsheetId, setSpreadsheetId] = useState('');
  const [sheetName, setSheetName] = useState('');
  const [waveId, setWaveId] = useState('');
  const [waves, setWaves] = useState<Wave[]>([]);
  const [sheets, setSheets] = useState<SheetInfo[]>([]);
  const [loadingWaves, setLoadingWaves] = useState(false);
  const [loadingSheets, setLoadingSheets] = useState(false);
  const [linking, setLinking] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState(false);

  const loadWaves = async () => {
    try {
      setLoadingWaves(true);
      const response = await api.getWaves();
      if (response.success && response.data) {
        setWaves(response.data);
      }
    } catch (err: any) {
      console.error('Ошибка загрузки волн:', err);
    } finally {
      setLoadingWaves(false);
    }
  };

  const loadSheets = useCallback(async () => {
    if (!spreadsheetId.trim()) {
      return;
    }

    try {
      setLoadingSheets(true);
      setError(null);
      const response = await api.getGoogleSheetsListForSpreadsheet(spreadsheetId.trim());
      if (response.success && response.data) {
        setSheets(response.data);
      } else {
        setError(response.error || 'Ошибка загрузки списка листов');
      }
    } catch (err: any) {
      setError(err.message || 'Ошибка загрузки списка листов');
    } finally {
      setLoadingSheets(false);
    }
  }, [spreadsheetId]);

  useEffect(() => {
    loadWaves();
  }, []);

  useEffect(() => {
    if (!spreadsheetId.trim()) {
      setSheets([]);
      setSheetName('');
      return;
    }

    // Debounce: загружаем листы через 500ms после того, как пользователь перестал вводить
    const timeoutId = setTimeout(() => {
      loadSheets();
    }, 500);

    return () => clearTimeout(timeoutId);
  }, [spreadsheetId, loadSheets]);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    if (!spreadsheetId.trim() || !sheetName.trim() || !waveId.trim()) {
      setError('Заполните все поля');
      return;
    }

    try {
      setLinking(true);
      setError(null);
      setSuccess(false);

      const response = await api.linkSheetToWave(
        spreadsheetId.trim(),
        sheetName.trim(),
        waveId.trim()
      );

      if (response.success) {
        setSuccess(true);
        setSpreadsheetId('');
        setSheetName('');
        setWaveId('');
        if (onLinked) {
          onLinked();
        }
        setTimeout(() => setSuccess(false), 3000);
      } else {
        setError(response.error || 'Ошибка привязки листа к волне');
      }
    } catch (err: any) {
      setError(err.message || 'Ошибка привязки листа к волне');
    } finally {
      setLinking(false);
    }
  };

  return (
    <div className="google-sheets-link-wave">
      <h3>Привязать лист к волне</h3>
      <form onSubmit={handleSubmit} className="link-form">
        <div className="form-group">
          <label htmlFor="link-spreadsheet-id">
            ID таблицы <span className="required">*</span>
          </label>
          <input
            id="link-spreadsheet-id"
            type="text"
            value={spreadsheetId}
            onChange={(e) => setSpreadsheetId(e.target.value)}
            placeholder="Введите ID таблицы"
            disabled={linking}
            required
          />
          <button
            type="button"
            onClick={loadSheets}
            disabled={loadingSheets || !spreadsheetId.trim()}
            className="btn btn-sm btn-secondary"
          >
            {loadingSheets ? 'Загрузка...' : 'Загрузить листы'}
          </button>
        </div>

        <div className="form-group">
          <label htmlFor="link-sheet-name">
            Лист <span className="required">*</span>
          </label>
          {loadingSheets ? (
            <div className="loading-indicator">Загрузка листов...</div>
          ) : sheets.length > 0 ? (
            <select
              id="link-sheet-name"
              value={sheetName}
              onChange={(e) => setSheetName(e.target.value)}
              disabled={linking || loadingSheets}
              required
            >
              <option value="">Выберите лист</option>
              {sheets.map((sheet) => (
                <option key={sheet.id} value={sheet.name}>
                  {sheet.name}
                </option>
              ))}
            </select>
          ) : (
            <input
              id="link-sheet-name"
              type="text"
              value={sheetName}
              onChange={(e) => setSheetName(e.target.value)}
              placeholder={spreadsheetId.trim() ? "Введите название листа или загрузите список" : "Сначала введите ID таблицы"}
              disabled={linking || !spreadsheetId.trim()}
              required
            />
          )}
        </div>

        <div className="form-group">
          <label htmlFor="link-wave-id">
            Волна <span className="required">*</span>
          </label>
          {loadingWaves ? (
            <div>Загрузка волн...</div>
          ) : (
            <select
              id="link-wave-id"
              value={waveId}
              onChange={(e) => setWaveId(e.target.value)}
              disabled={linking}
              required
            >
              <option value="">Выберите волну</option>
              {waves.map((wave) => (
                <option key={wave.id} value={wave.id}>
                  {wave.name} ({wave.status})
                </option>
              ))}
            </select>
          )}
        </div>

        {error && (
          <div className="message message-error">{error}</div>
        )}

        {success && (
          <div className="message message-success">Лист успешно привязан к волне!</div>
        )}

        <div className="form-actions">
          <button
            type="submit"
            disabled={linking || !spreadsheetId.trim() || !sheetName.trim() || !waveId.trim()}
            className="btn btn-primary"
          >
            {linking ? 'Привязка...' : 'Привязать'}
          </button>
        </div>
      </form>
    </div>
  );
}
