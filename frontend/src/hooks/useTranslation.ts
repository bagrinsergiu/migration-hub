import { useLanguage } from '../contexts/LanguageContext';
import { getTranslation, Translations } from '../utils/translations';

export function useTranslation() {
  const { language } = useLanguage();
  
  const t = (key: keyof Translations, params?: Record<string, string | number>): string => {
    return getTranslation(language, key, params);
  };
  
  return { t, language };
}
