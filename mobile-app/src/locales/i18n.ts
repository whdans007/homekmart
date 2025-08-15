import i18n from 'i18next';
import { initReactI18next } from 'react-i18next';
import AsyncStorage from '@react-native-async-storage/async-storage';
import { CACHE_KEYS, APP_CONFIG } from '@/constants/api';

// 언어 리소스 import
import en from './en.json';
import ko from './ko.json';

// 언어 감지 및 저장소 설정
const STORAGE_KEY = CACHE_KEYS.LANGUAGE;

const languageDetector = {
  type: 'languageDetector' as const,
  async: true,
  detect: async (callback: (lang: string) => void) => {
    try {
      // AsyncStorage에서 저장된 언어 가져오기
      const savedLanguage = await AsyncStorage.getItem(STORAGE_KEY);
      if (savedLanguage && APP_CONFIG.SUPPORTED_LANGUAGES.includes(savedLanguage)) {
        callback(savedLanguage);
        return;
      }
      
      // 저장된 언어가 없으면 기본 언어 사용
      callback(APP_CONFIG.DEFAULT_LANGUAGE);
    } catch (error) {
      console.error('언어 감지 오류:', error);
      callback(APP_CONFIG.DEFAULT_LANGUAGE);
    }
  },
  init: () => {},
  cacheUserLanguage: async (language: string) => {
    try {
      await AsyncStorage.setItem(STORAGE_KEY, language);
    } catch (error) {
      console.error('언어 저장 오류:', error);
    }
  },
};

// i18n 초기화
i18n
  .use(languageDetector)
  .use(initReactI18next)
  .init({
    // 언어 리소스
    resources: {
      en: {
        translation: en,
      },
      ko: {
        translation: ko,
      },
    },
    
    // 기본 설정
    fallbackLng: APP_CONFIG.DEFAULT_LANGUAGE,
    supportedLngs: APP_CONFIG.SUPPORTED_LANGUAGES,
    
    // 네임스페이스 설정
    defaultNS: 'translation',
    ns: ['translation'],
    
    // 보간 설정
    interpolation: {
      escapeValue: false, // React에서는 XSS 보호가 기본적으로 제공됨
      formatSeparator: ',',
    },
    
    // 개발 설정
    debug: __DEV__,
    
    // 키가 없을 때 설정
    saveMissing: __DEV__,
    missingKeyHandler: (lng, ns, key, fallbackValue) => {
      if (__DEV__) {
        console.warn(`Missing translation key: ${key} for language: ${lng}`);
      }
    },
    
    // 복수형 설정
    pluralSeparator: '_',
    contextSeparator: '_',
    
    // 캐시 설정
    load: 'languageOnly', // 'en-US' -> 'en'으로 변환
    
    // React 관련 설정
    react: {
      useSuspense: false, // React Native에서는 Suspense 비활성화
    },
  });

// 유틸리티 함수들
export const i18nUtils = {
  // 현재 언어 가져오기
  getCurrentLanguage: (): string => {
    return i18n.language || APP_CONFIG.DEFAULT_LANGUAGE;
  },
  
  // 언어 변경
  changeLanguage: async (language: string): Promise<void> => {
    if (!APP_CONFIG.SUPPORTED_LANGUAGES.includes(language)) {
      console.warn(`지원되지 않는 언어: ${language}`);
      return;
    }
    
    try {
      await i18n.changeLanguage(language);
      await AsyncStorage.setItem(STORAGE_KEY, language);
      console.log(`언어가 ${language}로 변경되었습니다.`);
    } catch (error) {
      console.error('언어 변경 오류:', error);
      throw error;
    }
  },
  
  // 지원되는 언어 목록
  getSupportedLanguages: () => {
    return APP_CONFIG.SUPPORTED_LANGUAGES.map(code => ({
      code,
      name: getLanguageName(code),
      nativeName: getLanguageNativeName(code),
    }));
  },
  
  // 언어별 날짜 포맷팅
  formatDate: (date: Date | string, options?: Intl.DateTimeFormatOptions): string => {
    const dateObj = typeof date === 'string' ? new Date(date) : date;
    const currentLang = i18nUtils.getCurrentLanguage();
    
    const defaultOptions: Intl.DateTimeFormatOptions = {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    };
    
    try {
      return dateObj.toLocaleDateString(getLocale(currentLang), {
        ...defaultOptions,
        ...options,
      });
    } catch (error) {
      console.error('날짜 포맷팅 오류:', error);
      return dateObj.toLocaleDateString('en-US', defaultOptions);
    }
  },
  
  // 언어별 숫자 포맷팅
  formatNumber: (number: number, options?: Intl.NumberFormatOptions): string => {
    const currentLang = i18nUtils.getCurrentLanguage();
    
    try {
      return number.toLocaleString(getLocale(currentLang), options);
    } catch (error) {
      console.error('숫자 포맷팅 오류:', error);
      return number.toLocaleString('en-US', options);
    }
  },
  
  // 필리핀 페소 포맷팅
  formatCurrency: (amount: number): string => {
    const currentLang = i18nUtils.getCurrentLanguage();
    
    try {
      // 필리핀 페소는 영어/한국어 모두 동일한 포맷 사용
      return `₱${amount.toLocaleString('en-PH', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
      })}`;
    } catch (error) {
      console.error('통화 포맷팅 오류:', error);
      return `₱${amount.toFixed(2)}`;
    }
  },
  
  // 상대 시간 표시 (예: "2분 전", "3시간 전")
  formatRelativeTime: (date: Date | string): string => {
    const dateObj = typeof date === 'string' ? new Date(date) : date;
    const now = new Date();
    const diffMs = now.getTime() - dateObj.getTime();
    const diffMinutes = Math.floor(diffMs / (1000 * 60));
    const diffHours = Math.floor(diffMs / (1000 * 60 * 60));
    const diffDays = Math.floor(diffMs / (1000 * 60 * 60 * 24));
    
    if (diffMinutes < 1) {
      return i18n.t('datetime.justNow');
    } else if (diffMinutes < 60) {
      return i18n.t('datetime.ago', { 
        replace: { time: `${diffMinutes} ${i18n.t('datetime.minutes')}` }
      });
    } else if (diffHours < 24) {
      return i18n.t('datetime.ago', { 
        replace: { time: `${diffHours} ${i18n.t('datetime.hours')}` }
      });
    } else if (diffDays < 7) {
      return i18n.t('datetime.ago', { 
        replace: { time: `${diffDays} ${i18n.t('datetime.days')}` }
      });
    } else {
      return i18nUtils.formatDate(dateObj, { 
        month: 'short', 
        day: 'numeric',
        year: diffDays > 365 ? 'numeric' : undefined,
      });
    }
  },
  
  // RTL 지원 여부 (현재는 LTR 언어만 지원)
  isRTL: (): boolean => {
    return false; // 영어, 한국어 모두 LTR
  },
};

// 헬퍼 함수들
function getLanguageName(code: string): string {
  const names: Record<string, string> = {
    en: 'English',
    ko: '한국어',
  };
  return names[code] || code;
}

function getLanguageNativeName(code: string): string {
  const nativeNames: Record<string, string> = {
    en: 'English',
    ko: '한국어',
  };
  return nativeNames[code] || code;
}

function getLocale(languageCode: string): string {
  const locales: Record<string, string> = {
    en: 'en-US',
    ko: 'ko-KR',
  };
  return locales[languageCode] || 'en-US';
}

// 타입 안전성을 위한 번역 키 유효성 검사 (개발 환경에서만)
export const t = (key: string, options?: any): string => {
  if (__DEV__) {
    // 개발 환경에서 키 존재 여부 확인
    const translation = i18n.t(key, options);
    if (translation === key && !i18n.exists(key)) {
      console.warn(`Translation key not found: ${key}`);
    }
    return translation;
  }
  
  return i18n.t(key, options);
};

export default i18n;