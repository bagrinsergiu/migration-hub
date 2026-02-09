export type Language = 'en' | 'ro' | 'ru';

export interface Translations {
  // Common
  back: string;
  loading: string;
  error: string;
  close: string;
  refresh: string;
  reset: string;
  filter: string;
  search: string;
  status: string;
  progress: string;
  completed: string;
  created: string;
  updated: string;
  switchToDarkTheme: string;
  switchToLightTheme: string;
  
  // Review page
  reviewPageTitle: string;
  backToProjects: string;
  overview: string;
  analysis: string;
  
  // Overview tab
  basicInfo: string;
  mbUuid: string;
  brizyProjectId: string;
  domain: string;
  sourceProject: string;
  clonedSite: string;
  basicInfoIdentifiers: string;
  basicInfoDates: string;
  errors: string;
  warnings: string;
  moreWarnings: string;
  
  // Analysis tab
  pageAnalysis: string;
  totalPages: string;
  averageRating: string;
  critical: string;
  high: string;
  medium: string;
  low: string;
  none: string;
  resetFilter: string;
  noAnalysis: string;
  noAnalysisDescription: string;
  
  // Page cards
  edit: string;
  openSourceSite: string;
  rating: string;
  tokens: string;
  noTitle: string;
  
  // Modal
  pageAnalysisTitle: string;
  screenshots: string;
  issues: string;
  json: string;
  qualityRating: string;
  severityLevel: string;
  analysisStatus: string;
  totalTokens: string;
  promptTokens: string;
  completionTokens: string;
  aiModel: string;
  sourcePage: string;
  migratedPage: string;
  analysisDate: string;
  summary: string;
  noIssues: string;
  screenshotsUnavailable: string;
  screenshotNotFound: string;
  
  // Wave review
  manualReview: string;
  workspace: string;
  projectsInMigration: string;
  searchPlaceholder: string;
  allStatuses: string;
  projectsNotAdded: string;
  projectsNotFound: string;
  shown: string;
  of: string;
  projects: string;
  reviewer: string;
  projectName: string;
  reviewReady: string;
  rememberFilter: string;
  
  // Errors
  tokenOrUuidNotSpecified: string;
  failedToLoadProjectDetails: string;
  errorLoadingData: string;
  dataNotFound: string;
  waveNotFound: string;
  checkReviewLink: string;
  logsNotFound: string;
  errorLoadingLogs: string;
  screenshotsNotFound: string;
  errorLoadingScreenshots: string;
  analysisNotFound: string;
  errorLoadingAnalysis: string;
  
  // Project review
  startReview: string;
  completeReview: string;
  reviewStatus: string;
  approved: string;
  rejected: string;
  needsChanges: string;
  pending: string;
  comment: string;
  optional: string;
  reviewCommentPlaceholder: string;
  lastReviewed: string;
  cancel: string;
  saving: string;
  save: string;
  pageChecked: string;
  markAsChecked: string;
  checked: string;
  reviewed: string;
}

const translations: Record<Language, Translations> = {
  en: {
    back: 'Back',
    loading: 'Loading...',
    error: 'Error',
    close: 'Close',
    refresh: 'Refresh',
    reset: 'Reset',
    filter: 'Filter',
    search: 'Search',
    status: 'Status',
    progress: 'Progress',
    completed: 'Completed',
    created: 'Created',
    updated: 'Updated',
    switchToDarkTheme: 'Switch to dark theme',
    switchToLightTheme: 'Switch to light theme',
    reviewPageTitle: 'Project Review',
    backToProjects: '← Back to projects list',
    overview: 'Overview',
    analysis: 'Analysis',
    basicInfo: 'Basic Information',
    mbUuid: 'MB UUID:',
    brizyProjectId: 'Brizy Project ID:',
    domain: 'Domain:',
    sourceProject: 'Source project:',
    clonedSite: 'Cloned site:',
    basicInfoIdentifiers: 'Identifiers',
    basicInfoDates: 'Dates & progress',
    errors: 'Errors',
    warnings: 'Warnings',
    moreWarnings: '... and {count} more warnings',
    pageAnalysis: 'Page Analysis',
    totalPages: 'Total Pages',
    averageRating: 'Average Rating',
    critical: 'Critical',
    high: 'High',
    medium: 'Medium',
    low: 'Low',
    none: 'None',
    resetFilter: 'Reset filter ({severity})',
    noAnalysis: 'Quality analysis has not been performed for this project yet.',
    noAnalysisDescription: 'Run migration with quality_analysis=true parameter to perform analysis.',
    edit: 'Edit',
    openSourceSite: 'Open Source Site',
    rating: 'Rating:',
    tokens: 'Tokens',
    noTitle: 'No title',
    pageAnalysisTitle: 'Page Analysis:',
    screenshots: 'Screenshots',
    issues: 'Issues',
    json: 'JSON',
    qualityRating: 'Quality Rating:',
    severityLevel: 'Severity Level:',
    analysisStatus: 'Analysis Status:',
    totalTokens: 'Total Tokens:',
    promptTokens: 'Input Tokens (prompt):',
    completionTokens: 'Output Tokens (completion):',
    aiModel: 'AI Model:',
    sourcePage: 'Source Page:',
    migratedPage: 'Migrated Page:',
    analysisDate: 'Analysis Date:',
    summary: 'Summary',
    noIssues: 'No issues found',
    screenshotsUnavailable: 'Screenshots unavailable',
    screenshotNotFound: 'Screenshot not found',
    manualReview: 'Manual Review:',
    workspace: 'Workspace:',
    projectsInMigration: 'Projects in Migration',
    searchPlaceholder: 'Search by UUID, domain or ID...',
    allStatuses: 'All Statuses',
    projectsNotAdded: 'Projects not yet added',
    projectsNotFound: 'Projects not found with applied filters',
    shown: 'Shown:',
    of: 'of',
    projects: 'projects',
    reviewer: 'Reviewer',
    projectName: 'Name',
    reviewReady: 'Review ready',
    rememberFilter: 'Remember filter',
    tokenOrUuidNotSpecified: 'Token or project UUID not specified',
    failedToLoadProjectDetails: 'Failed to load project details',
    errorLoadingData: 'Error loading data',
    dataNotFound: 'Data not found',
    waveNotFound: 'Wave not found or token is invalid',
    checkReviewLink: 'Please check the review link',
    logsNotFound: 'Logs not found',
    errorLoadingLogs: 'Error loading logs:',
    screenshotsNotFound: 'Screenshots not found',
    errorLoadingScreenshots: 'Error loading screenshots',
    analysisNotFound: 'Analysis not found',
    errorLoadingAnalysis: 'Error loading analysis',
    startReview: 'Start Review',
    completeReview: 'Complete Review',
    reviewStatus: 'Review Status',
    approved: 'Approved',
    rejected: 'Rejected',
    needsChanges: 'Needs Changes',
    pending: 'Pending',
    comment: 'Comment',
    optional: '(optional)',
    reviewCommentPlaceholder: 'Leave a comment to the review (if necessary)...',
    lastReviewed: 'Last Reviewed',
    cancel: 'Cancel',
    saving: 'Saving...',
    save: 'Save',
    pageChecked: 'Page checked',
    markAsChecked: 'Mark as checked',
    checked: 'Checked',
    reviewed: 'Reviewed',
  },
  ro: {
    back: 'Înapoi',
    loading: 'Se încarcă...',
    error: 'Eroare',
    close: 'Închide',
    refresh: 'Reîncarcă',
    reset: 'Resetează',
    filter: 'Filtru',
    search: 'Căutare',
    status: 'Status',
    progress: 'Progres',
    completed: 'Finalizat',
    created: 'Creat',
    updated: 'Actualizat',
    switchToDarkTheme: 'Comută la tema întunecată',
    switchToLightTheme: 'Comută la tema deschisă',
    reviewPageTitle: 'Revizuire Proiect',
    backToProjects: '← Înapoi la lista de proiecte',
    overview: 'Prezentare generală',
    analysis: 'Analiză',
    basicInfo: 'Informații de bază',
    mbUuid: 'MB UUID:',
    brizyProjectId: 'ID Proiect Brizy:',
    domain: 'Domeniu:',
    sourceProject: 'Proiect sursă:',
    clonedSite: 'Site clonat:',
    basicInfoIdentifiers: 'Identificatori',
    basicInfoDates: 'Date și progres',
    errors: 'Erori',
    warnings: 'Avertismente',
    moreWarnings: '... și încă {count} avertismente',
    pageAnalysis: 'Analiză Pagină',
    totalPages: 'Total Pagini',
    averageRating: 'Rating Mediu',
    critical: 'Critic',
    high: 'Ridicat',
    medium: 'Mediu',
    low: 'Scăzut',
    none: 'Niciunul',
    resetFilter: 'Resetează filtrul ({severity})',
    noAnalysis: 'Analiza calității nu a fost efectuată încă pentru acest proiect.',
    noAnalysisDescription: 'Rulează migrarea cu parametrul quality_analysis=true pentru a efectua analiza.',
    edit: 'Editează',
    openSourceSite: 'Deschide Site-ul Sursă',
    rating: 'Rating:',
    tokens: 'Tokenuri',
    noTitle: 'Fără titlu',
    pageAnalysisTitle: 'Analiză Pagină:',
    screenshots: 'Capturi de ecran',
    issues: 'Probleme',
    json: 'JSON',
    qualityRating: 'Rating Calitate:',
    severityLevel: 'Nivel Severitate:',
    analysisStatus: 'Status Analiză:',
    totalTokens: 'Total Tokenuri:',
    promptTokens: 'Tokenuri Intrare (prompt):',
    completionTokens: 'Tokenuri Ieșire (completion):',
    aiModel: 'Model AI:',
    sourcePage: 'Pagina Sursă:',
    migratedPage: 'Pagina Migrată:',
    analysisDate: 'Data Analiză:',
    summary: 'Rezumat',
    noIssues: 'Nu s-au găsit probleme',
    screenshotsUnavailable: 'Capturi de ecran indisponibile',
    screenshotNotFound: 'Captură de ecran negăsită',
    manualReview: 'Revizuire Manuală:',
    workspace: 'Spațiu de lucru:',
    projectsInMigration: 'Proiecte în Migrare',
    searchPlaceholder: 'Căutare după UUID, domeniu sau ID...',
    allStatuses: 'Toate Statusurile',
    projectsNotAdded: 'Proiecte încă neadăugate',
    projectsNotFound: 'Proiecte negăsite cu filtrele aplicate',
    shown: 'Afișate:',
    of: 'din',
    projects: 'proiecte',
    reviewer: 'Revizor',
    projectName: 'Nume',
    reviewReady: 'Revizuire finalizată',
    rememberFilter: 'Reține filtrul',
    tokenOrUuidNotSpecified: 'Token sau UUID proiect nespecificat',
    failedToLoadProjectDetails: 'Nu s-au putut încărca detaliile proiectului',
    errorLoadingData: 'Eroare la încărcarea datelor',
    dataNotFound: 'Date negăsite',
    waveNotFound: 'Undă negăsită sau token invalid',
    checkReviewLink: 'Vă rugăm să verificați linkul de revizuire',
    logsNotFound: 'Jurnale negăsite',
    errorLoadingLogs: 'Eroare la încărcarea jurnalelor:',
    screenshotsNotFound: 'Capturi de ecran negăsite',
    errorLoadingScreenshots: 'Eroare la încărcarea capturilor de ecran',
    analysisNotFound: 'Analiză negăsită',
    errorLoadingAnalysis: 'Eroare la încărcarea analizei',
    startReview: 'Începe Revizuirea',
    completeReview: 'Finalizează Revizuirea',
    reviewStatus: 'Status Revizuire',
    approved: 'Aprobat',
    rejected: 'Respins',
    needsChanges: 'Necesită Modificări',
    pending: 'În Așteptare',
    comment: 'Comentariu',
    optional: '(opțional)',
    reviewCommentPlaceholder: 'Lăsați un comentariu la revizuire (dacă este necesar)...',
    lastReviewed: 'Ultima Revizuire',
    cancel: 'Anulează',
    saving: 'Se salvează...',
    save: 'Salvează',
    pageChecked: 'Pagină verificată',
    markAsChecked: 'Marchează ca verificată',
    checked: 'Verificat',
    reviewed: 'Revizuit',
  },
  ru: {
    back: 'Назад',
    loading: 'Загрузка...',
    error: 'Ошибка',
    close: 'Закрыть',
    refresh: 'Обновить',
    reset: 'Сбросить',
    filter: 'Фильтр',
    search: 'Поиск',
    status: 'Статус',
    progress: 'Прогресс',
    completed: 'Завершено',
    created: 'Создано',
    updated: 'Обновлено',
    switchToDarkTheme: 'Переключить на темную тему',
    switchToLightTheme: 'Переключить на светлую тему',
    reviewPageTitle: 'Обзор проекта',
    backToProjects: '← Назад к списку проектов',
    overview: 'Обзор',
    analysis: 'Анализ',
    basicInfo: 'Основная информация',
    mbUuid: 'MB UUID:',
    brizyProjectId: 'Brizy Project ID:',
    domain: 'Домен:',
    sourceProject: 'Исходный проект:',
    clonedSite: 'Клонированный сайт:',
    basicInfoIdentifiers: 'Идентификаторы',
    basicInfoDates: 'Даты и прогресс',
    errors: 'Ошибки',
    warnings: 'Предупреждения',
    moreWarnings: '... и еще {count} предупреждений',
    pageAnalysis: 'Анализ страниц проекта',
    totalPages: 'Всего страниц',
    averageRating: 'Средний рейтинг',
    critical: 'Критичные',
    high: 'Высокие',
    medium: 'Средние',
    low: 'Низкие',
    none: 'Нет',
    resetFilter: 'Сбросить фильтр ({severity})',
    noAnalysis: 'Анализ качества для этого проекта еще не выполнен.',
    noAnalysisDescription: 'Запустите миграцию с параметром quality_analysis=true для выполнения анализа.',
    edit: 'Редактировать',
    openSourceSite: 'Открыть исходный сайт',
    rating: 'Рейтинг:',
    tokens: 'Токены',
    noTitle: 'Без названия',
    pageAnalysisTitle: 'Анализ страницы:',
    screenshots: 'Скриншоты',
    issues: 'Проблемы',
    json: 'JSON',
    qualityRating: 'Рейтинг качества:',
    severityLevel: 'Уровень критичности:',
    analysisStatus: 'Статус анализа:',
    totalTokens: 'Всего токенов:',
    promptTokens: 'Входные токены (prompt):',
    completionTokens: 'Выходные токены (completion):',
    aiModel: 'Модель AI:',
    sourcePage: 'Исходная страница:',
    migratedPage: 'Мигрированная страница:',
    analysisDate: 'Дата анализа:',
    summary: 'Краткое описание',
    noIssues: 'Проблем не обнаружено',
    screenshotsUnavailable: 'Скриншоты недоступны',
    screenshotNotFound: 'Скриншот не найден',
    manualReview: 'Мануальное ревью:',
    workspace: 'Workspace:',
    projectsInMigration: 'Проекты в миграции',
    searchPlaceholder: 'Поиск по UUID, домену или ID...',
    allStatuses: 'Все статусы',
    projectsNotAdded: 'Проекты еще не добавлены',
    projectsNotFound: 'Проекты не найдены по заданным фильтрам',
    shown: 'Показано:',
    of: 'из',
    projects: 'проектов',
    reviewer: 'Ревьюер',
    projectName: 'Имя',
    reviewReady: 'Ревью готово',
    rememberFilter: 'Запомнить фильтр',
    tokenOrUuidNotSpecified: 'Токен или UUID проекта не указан',
    failedToLoadProjectDetails: 'Не удалось загрузить детали проекта',
    errorLoadingData: 'Ошибка загрузки данных',
    dataNotFound: 'Данные не найдены',
    waveNotFound: 'Волна не найдена или токен недействителен',
    checkReviewLink: 'Проверьте правильность ссылки для ревью',
    logsNotFound: 'Логи не найдены',
    errorLoadingLogs: 'Ошибка загрузки логов:',
    screenshotsNotFound: 'Скриншоты не найдены',
    errorLoadingScreenshots: 'Ошибка загрузки скриншотов',
    analysisNotFound: 'Анализ не найден',
    errorLoadingAnalysis: 'Ошибка загрузки анализа',
    startReview: 'Начать ревью',
    completeReview: 'Завершить ревью',
    reviewStatus: 'Статус ревью',
    approved: 'Одобрено',
    rejected: 'Отклонено',
    needsChanges: 'Требуются изменения',
    pending: 'Ожидает ревью',
    comment: 'Комментарий',
    optional: '(необязательно)',
    reviewCommentPlaceholder: 'Оставьте комментарий к ревью (если необходимо)...',
    lastReviewed: 'Последнее ревью',
    cancel: 'Отмена',
    saving: 'Сохранение...',
    save: 'Сохранить',
    pageChecked: 'Страница проверена',
    markAsChecked: 'Отметить как проверенную',
    checked: 'Проверено',
    reviewed: 'Проверено',
  },
};

export const getTranslation = (lang: Language, key: keyof Translations, params?: Record<string, string | number>): string => {
  let translation = translations[lang][key] || translations.en[key] || key;
  
  if (params) {
    Object.entries(params).forEach(([paramKey, paramValue]) => {
      translation = translation.replace(`{${paramKey}}`, String(paramValue));
    });
  }
  
  return translation;
};

export const defaultLanguage: Language = 'en';
export const availableLanguages: Language[] = ['en', 'ro', 'ru'];
