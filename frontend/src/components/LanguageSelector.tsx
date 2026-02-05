import { useLanguage } from '../contexts/LanguageContext';
import './LanguageSelector.css';

const languageNames: Record<string, string> = {
  en: 'English',
  ro: 'Română',
  ru: 'Русский',
};

export default function LanguageSelector() {
  const { language, setLanguage, availableLanguages } = useLanguage();

  return (
    <div className="language-selector">
      <select
        value={language}
        onChange={(e) => setLanguage(e.target.value as any)}
        className="language-select"
      >
        {availableLanguages.map((lang) => (
          <option key={lang} value={lang}>
            {languageNames[lang]}
          </option>
        ))}
      </select>
    </div>
  );
}
