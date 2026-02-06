import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { api } from '../api/client';
import { useUser } from '../contexts/UserContext';
import './Login.css';

export default function Login() {
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  const navigate = useNavigate();
  const { setUser } = useUser();

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);
    setLoading(true);

    try {
      const response = await api.login(username, password);

      if (response.success) {
        // Сохраняем session_id в localStorage для использования в API запросах
        // Куки уже установлены сервером автоматически
        if (response.data?.session_id) {
          localStorage.setItem('dashboard_session', response.data.session_id);
          // Также устанавливаем куки вручную на случай, если сервер не установил
          // (для совместимости и надежности)
          document.cookie = `dashboard_session=${response.data.session_id}; path=/; max-age=${7 * 24 * 60 * 60}; SameSite=Lax`;
        }
        // Сохраняем информацию о пользователе
        if (response.data?.user) {
          localStorage.setItem('dashboard_user', JSON.stringify(response.data.user));
        }
        
        // Устанавливаем пользователя напрямую в контекст (быстрее, чем refreshUser)
        if (response.data?.user) {
          setUser(response.data.user);
        }
        
        // Определяем страницу для редиректа
        // Проверяем, является ли пользователь админом (имеет роль admin)
        const user = response.data?.user;
        const isAdmin = user?.roles?.some((role: any) => role.name === 'admin');
        
        // Редиректим на страницу управления пользователями для админов
        // или на главную страницу для остальных
        const redirectPath = isAdmin ? '/users' : '/';
        navigate(redirectPath);
      } else {
        setError(response.error || 'Ошибка авторизации');
      }
    } catch (err: any) {
      console.error('Login error:', err);
      // Проверяем, есть ли ответ от сервера
      if (err.response) {
        const errorData = err.response.data;
        setError(errorData?.error || errorData?.message || 'Ошибка авторизации');
      } else if (err.request) {
        setError('Не удалось подключиться к серверу. Проверьте, что сервер запущен на порту 8000.');
      } else {
        setError(err.message || 'Ошибка подключения к серверу');
      }
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="login-container">
      <div className="login-box">
        <h1>🚀 MB Migration Dashboard</h1>
        <h2>Вход в систему</h2>
        <form onSubmit={handleSubmit}>
          <div className="form-group">
            <label htmlFor="username">Логин:</label>
            <input
              type="text"
              id="username"
              value={username}
              onChange={(e) => setUsername(e.target.value)}
              required
              autoFocus
              disabled={loading}
            />
          </div>
          <div className="form-group">
            <label htmlFor="password">Пароль:</label>
            <input
              type="password"
              id="password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              required
              disabled={loading}
            />
          </div>
          {error && (
            <div className="error-message">
              {error}
            </div>
          )}
          <button type="submit" className="btn btn-primary" disabled={loading}>
            {loading ? 'Вход...' : 'Войти'}
          </button>
        </form>
      </div>
    </div>
  );
}
