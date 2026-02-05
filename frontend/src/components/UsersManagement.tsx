import { useState, useEffect } from 'react';
import { api } from '../api/client';
import './common.css';
import './UsersManagement.css';

interface User {
  id: number;
  username: string;
  email?: string;
  full_name?: string;
  is_active: number;
  created_at: string;
  last_login?: string;
  roles?: Role[];
}

interface Role {
  id: number;
  name: string;
  description?: string;
}

export default function UsersManagement() {
  const [users, setUsers] = useState<User[]>([]);
  const [roles, setRoles] = useState<Role[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [showCreateModal, setShowCreateModal] = useState(false);
  const [editingUser, setEditingUser] = useState<User | null>(null);
  const [formData, setFormData] = useState({
    username: '',
    email: '',
    full_name: '',
    password: '',
    is_active: true,
    role_ids: [] as number[]
  });

  useEffect(() => {
    loadData();
  }, []);

  const loadData = async () => {
    try {
      setLoading(true);
      setError(null);
      
      const [usersResponse, rolesResponse] = await Promise.all([
        api.getUsers(),
        api.getRoles()
      ]);

      if (usersResponse.success && usersResponse.data) {
        setUsers(usersResponse.data);
      }

      if (rolesResponse.success && rolesResponse.data) {
        setRoles(rolesResponse.data);
      }
    } catch (err: any) {
      setError(err.message || 'Ошибка загрузки данных');
    } finally {
      setLoading(false);
    }
  };

  const handleCreateUser = async () => {
    try {
      setError(null);
      
      if (!formData.username || !formData.password) {
        setError('Имя пользователя и пароль обязательны');
        return;
      }

      const response = await api.createUser(formData);
      
      if (response.success) {
        setShowCreateModal(false);
        resetForm();
        await loadData();
      } else {
        setError(response.error || 'Ошибка создания пользователя');
      }
    } catch (err: any) {
      setError(err.message || 'Ошибка создания пользователя');
    }
  };

  const handleUpdateUser = async () => {
    if (!editingUser) return;

    try {
      setError(null);
      
      const updateData: any = { ...formData };
      if (!updateData.password) {
        delete updateData.password; // Не обновляем пароль, если он пустой
      }

      const response = await api.updateUser(editingUser.id, updateData);
      
      if (response.success) {
        setEditingUser(null);
        resetForm();
        await loadData();
      } else {
        setError(response.error || 'Ошибка обновления пользователя');
      }
    } catch (err: any) {
      setError(err.message || 'Ошибка обновления пользователя');
    }
  };

  const handleDeleteUser = async (userId: number) => {
    if (!confirm('Вы уверены, что хотите удалить этого пользователя?')) {
      return;
    }

    try {
      setError(null);
      const response = await api.deleteUser(userId);
      
      if (response.success) {
        await loadData();
      } else {
        setError(response.error || 'Ошибка удаления пользователя');
      }
    } catch (err: any) {
      setError(err.message || 'Ошибка удаления пользователя');
    }
  };

  const resetForm = () => {
    setFormData({
      username: '',
      email: '',
      full_name: '',
      password: '',
      is_active: true,
      role_ids: []
    });
  };

  const openEditModal = (user: User) => {
    setEditingUser(user);
    setFormData({
      username: user.username,
      email: user.email || '',
      full_name: user.full_name || '',
      password: '',
      is_active: user.is_active === 1,
      role_ids: user.roles?.map(r => r.id) || []
    });
  };

  if (loading) {
    return (
      <div className="loading-container">
        <div className="spinner"></div>
        <p>Загрузка пользователей...</p>
      </div>
    );
  }

  return (
    <div className="users-management">
      <div className="page-header">
        <h2>Управление пользователями</h2>
        <button
          className="btn btn-primary"
          onClick={() => {
            resetForm();
            setEditingUser(null);
            setShowCreateModal(true);
          }}
        >
          + Создать пользователя
        </button>
      </div>

      {error && (
        <div className="alert alert-error">
          {error}
        </div>
      )}

      <div className="users-table-container">
        <table className="users-table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Имя пользователя</th>
              <th>Email</th>
              <th>Полное имя</th>
              <th>Роли</th>
              <th>Статус</th>
              <th>Последний вход</th>
              <th>Действия</th>
            </tr>
          </thead>
          <tbody>
            {users.length === 0 ? (
              <tr>
                <td colSpan={8} className="empty-message">
                  Пользователи не найдены
                </td>
              </tr>
            ) : (
              users.map((user) => (
                <tr key={user.id}>
                  <td>{user.id}</td>
                  <td>{user.username}</td>
                  <td>{user.email || '-'}</td>
                  <td>{user.full_name || '-'}</td>
                  <td>
                    {user.roles && user.roles.length > 0 ? (
                      <div className="roles-list">
                        {user.roles.map(role => (
                          <span key={role.id} className="role-badge">
                            {role.name}
                          </span>
                        ))}
                      </div>
                    ) : (
                      '-'
                    )}
                  </td>
                  <td>
                    <span className={`status-badge ${user.is_active ? 'active' : 'inactive'}`}>
                      {user.is_active ? 'Активен' : 'Неактивен'}
                    </span>
                  </td>
                  <td>{user.last_login ? new Date(user.last_login).toLocaleString('ru-RU') : '-'}</td>
                  <td>
                    <div className="action-buttons">
                      <button
                        className="btn btn-sm btn-primary"
                        onClick={() => openEditModal(user)}
                      >
                        Редактировать
                      </button>
                      <button
                        className="btn btn-sm btn-danger"
                        onClick={() => handleDeleteUser(user.id)}
                      >
                        Удалить
                      </button>
                    </div>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>

      {/* Модальное окно создания/редактирования */}
      {(showCreateModal || editingUser) && (
        <div className="modal-overlay" onClick={() => {
          setShowCreateModal(false);
          setEditingUser(null);
          resetForm();
        }}>
          <div className="modal-content" onClick={(e) => e.stopPropagation()}>
            <div className="modal-header">
              <h3>{editingUser ? 'Редактировать пользователя' : 'Создать пользователя'}</h3>
              <button
                className="btn-close"
                onClick={() => {
                  setShowCreateModal(false);
                  setEditingUser(null);
                  resetForm();
                }}
              >
                ×
              </button>
            </div>
            <div className="modal-body">
              <div className="form-group">
                <label>Имя пользователя *</label>
                <input
                  type="text"
                  value={formData.username}
                  onChange={(e) => setFormData({ ...formData, username: e.target.value })}
                  disabled={!!editingUser}
                  required
                />
              </div>
              <div className="form-group">
                <label>Email</label>
                <input
                  type="email"
                  value={formData.email}
                  onChange={(e) => setFormData({ ...formData, email: e.target.value })}
                />
              </div>
              <div className="form-group">
                <label>Полное имя</label>
                <input
                  type="text"
                  value={formData.full_name}
                  onChange={(e) => setFormData({ ...formData, full_name: e.target.value })}
                />
              </div>
              <div className="form-group">
                <label>{editingUser ? 'Новый пароль (оставьте пустым, чтобы не менять)' : 'Пароль *'}</label>
                <input
                  type="password"
                  value={formData.password}
                  onChange={(e) => setFormData({ ...formData, password: e.target.value })}
                  required={!editingUser}
                />
              </div>
              <div className="form-group">
                <label>Роли</label>
                <div className="roles-checkboxes">
                  {roles.map(role => (
                    <label key={role.id} className="checkbox-label">
                      <input
                        type="checkbox"
                        checked={formData.role_ids.includes(role.id)}
                        onChange={(e) => {
                          if (e.target.checked) {
                            setFormData({ ...formData, role_ids: [...formData.role_ids, role.id] });
                          } else {
                            setFormData({ ...formData, role_ids: formData.role_ids.filter(id => id !== role.id) });
                          }
                        }}
                      />
                      <span>{role.name}</span>
                      {role.description && <small>{role.description}</small>}
                    </label>
                  ))}
                </div>
              </div>
              <div className="form-group">
                <label className="checkbox-label">
                  <input
                    type="checkbox"
                    checked={formData.is_active}
                    onChange={(e) => setFormData({ ...formData, is_active: e.target.checked })}
                  />
                  <span>Активен</span>
                </label>
              </div>
            </div>
            <div className="modal-footer">
              <button
                className="btn btn-secondary"
                onClick={() => {
                  setShowCreateModal(false);
                  setEditingUser(null);
                  resetForm();
                }}
              >
                Отмена
              </button>
              <button
                className="btn btn-primary"
                onClick={editingUser ? handleUpdateUser : handleCreateUser}
              >
                {editingUser ? 'Сохранить' : 'Создать'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
