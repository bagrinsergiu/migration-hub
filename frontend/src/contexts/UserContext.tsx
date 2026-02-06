import { createContext, useContext, useState, useEffect, ReactNode } from 'react';
import { api } from '../api/client';

interface Permission {
  id: number;
  name: string;
  resource: string;
  action: string;
}

interface Role {
  id: number;
  name: string;
  description?: string;
}

interface User {
  id: number;
  username: string;
  email?: string;
  full_name?: string;
  is_active: number;
  permissions?: Permission[];
  roles?: Role[];
}

interface UserContextType {
  user: User | null;
  loading: boolean;
  hasPermission: (resource: string, action: string) => boolean;
  isAdmin: () => boolean;
  refreshUser: () => Promise<void>;
  setUser: (user: User | null) => void;
}

const UserContext = createContext<UserContextType | undefined>(undefined);

export function UserProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [loading, setLoading] = useState(true);

  // Функция для получения session_id из куки или localStorage
  const getSessionId = (): string | null => {
    // Сначала пытаемся получить из куки
    if (typeof document !== 'undefined') {
      const cookies = document.cookie.split(';');
      for (let cookie of cookies) {
        const [name, value] = cookie.trim().split('=');
        if (name === 'dashboard_session' && value) {
          // Синхронизируем с localStorage
          localStorage.setItem('dashboard_session', value);
          return value;
        }
      }
    }
    
    // Если не нашли в куки, берем из localStorage
    return localStorage.getItem('dashboard_session');
  };

  const loadUser = async () => {
    try {
      const sessionId = getSessionId();
      if (!sessionId) {
        setUser(null);
        setLoading(false);
        return;
      }

      const response = await api.checkAuth();
      if (response.success && response.data?.authenticated && response.data?.user) {
        setUser(response.data.user);
        // Сохраняем пользователя в localStorage для быстрого доступа
        localStorage.setItem('dashboard_user', JSON.stringify(response.data.user));
      } else {
        setUser(null);
        localStorage.removeItem('dashboard_session');
        localStorage.removeItem('dashboard_user');
        // Удаляем куки
        if (typeof document !== 'undefined') {
          document.cookie = 'dashboard_session=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT';
        }
      }
    } catch (err) {
      setUser(null);
      localStorage.removeItem('dashboard_session');
      localStorage.removeItem('dashboard_user');
      // Удаляем куки
      if (typeof document !== 'undefined') {
        document.cookie = 'dashboard_session=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT';
      }
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadUser();
  }, []);

  const isAdmin = (): boolean => {
    if (!user || !user.roles) {
      return false;
    }
    return user.roles.some((role: any) => role.name === 'admin');
  };

  const hasPermission = (resource: string, action: string): boolean => {
    if (!user) {
      return false;
    }

    // Админ имеет доступ ко всему
    if (isAdmin()) {
      return true;
    }

    if (!user.permissions) {
      return false;
    }

    // Проверяем точное совпадение или manage для ресурса
    return user.permissions.some(
      (perm) =>
        (perm.resource === resource && perm.action === action) ||
        (perm.resource === resource && perm.action === 'manage')
    );
  };

  const refreshUser = async () => {
    setLoading(true);
    await loadUser();
  };

  const setUserDirectly = (newUser: User | null) => {
    setUser(newUser);
    setLoading(false);
  };

  return (
    <UserContext.Provider value={{ user, loading, hasPermission, isAdmin, refreshUser, setUser: setUserDirectly }}>
      {children}
    </UserContext.Provider>
  );
}

export function useUser() {
  const context = useContext(UserContext);
  if (context === undefined) {
    throw new Error('useUser must be used within a UserProvider');
  }
  return context;
}
