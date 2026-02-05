import { Link, useLocation } from 'react-router-dom';
import { useTheme } from '../contexts/ThemeContext';
import { useUser } from '../contexts/UserContext';
import DropdownMenu from './DropdownMenu';
import MigrationLogo from './MigrationLogo';
import MigrationServerStatus from './MigrationServerStatus';
import './Layout.css';

interface LayoutProps {
  children: React.ReactNode;
}

export default function Layout({ children }: LayoutProps) {
  const location = useLocation();
  const { theme, toggleTheme } = useTheme();
  const { hasPermission } = useUser();

  const isActive = (path: string) => location.pathname === path;

  return (
    <div className="layout">
      <header className="header">
        <div className="header-content">
          <div className="logo-wrapper">
            <MigrationLogo />
          </div>
          <div className="header-right">
            <nav className="nav">
              {/* Группа: Миграции */}
              {(hasPermission('migrations', 'view') || hasPermission('migrations', 'create') || hasPermission('logs', 'view') || hasPermission('waves', 'view')) && (
                <DropdownMenu
                  label="Миграции"
                  items={[
                    ...(hasPermission('migrations', 'view') ? [{ label: 'Список миграций', path: '/', icon: '📋' }] : []),
                    ...(hasPermission('migrations', 'create') ? [{ label: 'Запустить миграцию', path: '/run', icon: '🚀' }] : []),
                    ...(hasPermission('waves', 'view') ? [{ label: 'Волны', path: '/wave', icon: '🌊' }] : []),
                    ...(hasPermission('logs', 'view') ? [{ label: 'Логи', path: '/logs', icon: '📄' }] : []),
                  ]}
                  isActive={isActive('/') || isActive('/run') || isActive('/wave') || location.pathname.startsWith('/wave/') || isActive('/logs')}
                />
              )}

              {/* Тестирование */}
              {hasPermission('test', 'view') && (
                <Link 
                  to="/test" 
                  className={`nav-link ${isActive('/test') || location.pathname.startsWith('/test/') ? 'active' : ''}`}
                >
                  <span className="nav-icon">🧪</span>
                  Тестирование
                </Link>
              )}

              {/* Google Sheets */}
              {hasPermission('settings', 'view') && (
                <Link 
                  to="/google-sheets" 
                  className={`nav-link ${isActive('/google-sheets') ? 'active' : ''}`}
                >
                  <span className="nav-icon">📊</span>
                  Google Sheets
                </Link>
              )}

              {/* Группа: Управление */}
              {(hasPermission('users', 'view') || hasPermission('settings', 'view')) && (
                <DropdownMenu
                  label="Управление"
                  items={[
                    ...(hasPermission('users', 'view') ? [{ label: 'Пользователи', path: '/users', icon: '👥' }] : []),
                    ...(hasPermission('settings', 'view') ? [{ label: 'Настройки', path: '/settings', icon: '🔧' }] : []),
                  ]}
                  isActive={isActive('/users') || isActive('/settings')}
                />
              )}
            </nav>
            <MigrationServerStatus />
            <button className="theme-toggle" onClick={toggleTheme} aria-label="Переключить тему">
              {theme === 'light' ? '🌙' : '☀️'}
            </button>
          </div>
        </div>
      </header>
      <main className="main">
        {children}
      </main>
    </div>
  );
}
