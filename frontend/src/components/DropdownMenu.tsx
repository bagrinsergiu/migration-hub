import { useState, useRef, useEffect } from 'react';
import { Link, useLocation } from 'react-router-dom';
import './DropdownMenu.css';

interface DropdownItem {
  label: string;
  path: string;
  icon?: string;
}

interface DropdownMenuProps {
  label: string;
  icon?: string;
  items: DropdownItem[];
  isActive?: boolean;
}

export default function DropdownMenu({ label, icon, items, isActive }: DropdownMenuProps) {
  const [isOpen, setIsOpen] = useState(false);
  const dropdownRef = useRef<HTMLDivElement>(null);
  const location = useLocation();

  // Проверяем, активен ли какой-либо пункт меню
  const hasActiveItem = items.some(item => {
    if (item.path === '/') {
      return location.pathname === '/' || location.pathname === '';
    }
    return location.pathname.startsWith(item.path) || location.pathname === item.path;
  });

  // Закрываем меню при клике вне его
  useEffect(() => {
    const handleClickOutside = (event: MouseEvent) => {
      if (dropdownRef.current && !dropdownRef.current.contains(event.target as Node)) {
        setIsOpen(false);
      }
    };

    document.addEventListener('mousedown', handleClickOutside);
    return () => {
      document.removeEventListener('mousedown', handleClickOutside);
    };
  }, []);

  // Закрываем меню при изменении маршрута
  useEffect(() => {
    setIsOpen(false);
  }, [location.pathname]);

  return (
    <div className="dropdown-menu" ref={dropdownRef}>
      <button
        className={`dropdown-trigger ${isActive || hasActiveItem ? 'active' : ''}`}
        onClick={() => setIsOpen(!isOpen)}
        aria-expanded={isOpen}
        aria-haspopup="true"
      >
        {icon && <span className="dropdown-icon">{icon}</span>}
        <span>{label}</span>
        <span className={`dropdown-arrow ${isOpen ? 'open' : ''}`}>▼</span>
      </button>
      {isOpen && (
        <div className="dropdown-content">
          {items.map((item, index) => {
            const isItemActive = item.path === '/' 
              ? (location.pathname === '/' || location.pathname === '')
              : (location.pathname.startsWith(item.path) || location.pathname === item.path);
            
            return (
              <Link
                key={index}
                to={item.path}
                className={`dropdown-item ${isItemActive ? 'active' : ''}`}
                onClick={() => setIsOpen(false)}
              >
                {item.icon && <span className="dropdown-item-icon">{item.icon}</span>}
                <span>{item.label}</span>
              </Link>
            );
          })}
        </div>
      )}
    </div>
  );
}
