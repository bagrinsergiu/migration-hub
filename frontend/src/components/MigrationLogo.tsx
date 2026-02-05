import { Link } from 'react-router-dom';
import './MigrationLogo.css';

export default function MigrationLogo() {
  return (
    <Link to="/" className="migration-logo">
      <div className="logo-container">
        <svg 
          className="logo-icon" 
          viewBox="0 0 160 32" 
          xmlns="http://www.w3.org/2000/svg"
          aria-hidden="true"
        >
          {/* Анимированные стрелки миграции */}
          <g className="migration-arrows">
            {/* Левая стрелка */}
            <path
              className="arrow arrow-left"
              d="M 6 16 L 2 12 L 2 14 L 0 14 L 0 18 L 2 18 L 2 20 Z"
              fill="currentColor"
              stroke="currentColor"
              strokeWidth="0.5"
            />
            {/* Центральная стрелка */}
            <path
              className="arrow arrow-center"
              d="M 18 16 L 14 12 L 14 14 L 12 14 L 12 18 L 14 18 L 14 20 Z"
              fill="currentColor"
              stroke="currentColor"
              strokeWidth="0.5"
            />
            {/* Правая стрелка */}
            <path
              className="arrow arrow-right"
              d="M 30 16 L 26 12 L 26 14 L 24 14 L 24 18 L 26 18 L 26 20 Z"
              fill="currentColor"
              stroke="currentColor"
              strokeWidth="0.5"
            />
          </g>
          {/* Текст MB Migration */}
          <text 
            x="38" 
            y="20" 
            className="logo-text"
            fill="currentColor"
            fontSize="14"
            fontWeight="600"
            fontFamily="system-ui, -apple-system, sans-serif"
            letterSpacing="0.5px"
          >
            MB Migration
          </text>
        </svg>
        <span className="logo-label">Dashboard</span>
      </div>
    </Link>
  );
}
