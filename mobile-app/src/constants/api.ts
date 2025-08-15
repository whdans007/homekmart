// API 설정 상수

// 기본 API 설정
export const API_CONFIG = {
  BASE_URL: 'http://192-168-0-138.philsarang.direct.quickconnect.to/min',
  TIMEOUT: 30000, // 30초
  RETRIES: 3,
} as const;

// API 엔드포인트
export const API_ENDPOINTS = {
  // 인증
  AUTH: {
    GOOGLE_LOGIN: '/auth/google_login.php',
    LOGOUT: '/auth/logout.php',
    PROFILE: '/auth/profile.php',
    REFRESH: '/auth/refresh.php',
  },
  
  // 상품
  PRODUCTS: {
    LIST: '/enhanced_products_api.php',
    DETAIL: '/product_detail_api.php',
    SEARCH: '/enhanced_products_api.php',
    BASIC: '/correct_products_api.php',
    SAMPLE: '/simple_working_api.php',
  },
  
  // 카테고리
  CATEGORIES: {
    LIST: '/working_categories_api.php',
    DETAIL: '/category_detail_api.php',
  },
  
  // 장바구니
  CART: {
    LIST: '/api_cart.php',
    ADD: '/api_cart.php',
    UPDATE: '/api_cart.php',
    REMOVE: '/api_cart.php',
    CLEAR: '/api_cart.php',
  },
  
  // 주문
  ORDERS: {
    LIST: '/delivery_orders_api.php',
    CREATE: '/delivery_orders_api.php',
    DETAIL: '/delivery_orders_api.php',
    UPDATE: '/delivery_orders_api.php',
    CANCEL: '/delivery_orders_api.php',
  },
  
  // 배달 주소
  ADDRESSES: {
    LIST: '/delivery_addresses_api.php',
    CREATE: '/delivery_addresses_api.php',
    UPDATE: '/delivery_addresses_api.php',
    DELETE: '/delivery_addresses_api.php',
  },
  
  // 배달 구역 & 위치
  DELIVERY: {
    ZONES: '/delivery_zones_api.php',
    COVERAGE: '/delivery_zones_api.php',
    NEARBY: '/delivery_zones_api.php',
  },
  
  // 위치 검색
  LOCATION: {
    SEARCH: '/location_search_api.php',
    AUTOCOMPLETE: '/location_search_api.php',
  },
  
  // 유틸리티
  UTILS: {
    SCHEMA_INSPECTOR: '/db_schema_inspector.php',
    TEST: '/api_test_page.php',
    STATUS: '/step_by_step.php',
  },
} as const;

// HTTP 메서드
export const HTTP_METHODS = {
  GET: 'GET',
  POST: 'POST',
  PUT: 'PUT',
  DELETE: 'DELETE',
  PATCH: 'PATCH',
} as const;

// API 응답 코드
export const API_STATUS_CODES = {
  SUCCESS: 200,
  CREATED: 201,
  NO_CONTENT: 204,
  BAD_REQUEST: 400,
  UNAUTHORIZED: 401,
  FORBIDDEN: 403,
  NOT_FOUND: 404,
  CONFLICT: 409,
  INTERNAL_SERVER_ERROR: 500,
} as const;

// 캐시 키
export const CACHE_KEYS = {
  USER_PROFILE: 'user_profile',
  AUTH_TOKEN: 'auth_token',
  PRODUCTS: 'products',
  CATEGORIES: 'categories',
  ADDRESSES: 'delivery_addresses',
  CART: 'shopping_cart',
  ORDERS: 'orders',
  DELIVERY_ZONES: 'delivery_zones',
  LOCATION_SEARCH: 'location_search',
  APP_SETTINGS: 'app_settings',
  LANGUAGE: 'selected_language',
} as const;

// 요청 헤더
export const DEFAULT_HEADERS = {
  'Content-Type': 'application/json',
  'Accept': 'application/json',
  'X-App-Version': '1.0.0',
  'X-Platform': 'mobile',
} as const;

// 필리핀 화폐 설정
export const CURRENCY = {
  CODE: 'PHP',
  SYMBOL: '₱',
  NAME: 'Philippine Peso',
  DECIMAL_PLACES: 2,
} as const;

// 배달 관련 상수
export const DELIVERY = {
  DEFAULT_FEE: 50.00,
  FREE_DELIVERY_MINIMUM: 1000.00,
  ESTIMATED_TIME: {
    MIN: 30,
    MAX: 90,
    UNIT: 'minutes',
  },
  COVERAGE_AREAS: [
    'Metro Manila',
    'Cebu',
    'Davao',
  ],
} as const;

// 주문 상태
export const ORDER_STATUS = {
  PENDING: 'pending',
  CONFIRMED: 'confirmed',
  PREPARING: 'preparing',
  DELIVERING: 'delivering',
  DELIVERED: 'delivered',
  CANCELLED: 'cancelled',
} as const;

export const ORDER_STATUS_LABELS = {
  [ORDER_STATUS.PENDING]: {
    en: 'Pending',
    ko: '대기중',
  },
  [ORDER_STATUS.CONFIRMED]: {
    en: 'Confirmed',
    ko: '확인됨',
  },
  [ORDER_STATUS.PREPARING]: {
    en: 'Preparing',
    ko: '준비중',
  },
  [ORDER_STATUS.DELIVERING]: {
    en: 'Delivering',
    ko: '배송중',
  },
  [ORDER_STATUS.DELIVERED]: {
    en: 'Delivered',
    ko: '배송완료',
  },
  [ORDER_STATUS.CANCELLED]: {
    en: 'Cancelled',
    ko: '취소됨',
  },
} as const;

// 결제 방법
export const PAYMENT_METHODS = {
  COD: 'cod',
} as const;

export const PAYMENT_METHOD_LABELS = {
  [PAYMENT_METHODS.COD]: {
    en: 'Cash on Delivery (COD)',
    ko: '착불 결제 (COD)',
  },
} as const;

// 재고 상태
export const STOCK_STATUS = {
  IN_STOCK: 'in_stock',
  LOW_STOCK: 'low_stock',
  OUT_OF_STOCK: 'out_of_stock',
} as const;

export const STOCK_STATUS_LABELS = {
  [STOCK_STATUS.IN_STOCK]: {
    en: 'In Stock',
    ko: '재고 있음',
  },
  [STOCK_STATUS.LOW_STOCK]: {
    en: 'Low Stock',
    ko: '재고 부족',
  },
  [STOCK_STATUS.OUT_OF_STOCK]: {
    en: 'Out of Stock',
    ko: '품절',
  },
} as const;

// 에러 메시지
export const ERROR_MESSAGES = {
  NETWORK_ERROR: {
    en: 'Network connection error. Please check your internet connection.',
    ko: '네트워크 연결 오류. 인터넷 연결을 확인해주세요.',
  },
  TIMEOUT_ERROR: {
    en: 'Request timeout. Please try again.',
    ko: '요청 시간 초과. 다시 시도해주세요.',
  },
  SERVER_ERROR: {
    en: 'Server error. Please try again later.',
    ko: '서버 오류. 나중에 다시 시도해주세요.',
  },
  UNAUTHORIZED: {
    en: 'Please log in to continue.',
    ko: '계속하려면 로그인해주세요.',
  },
  FORBIDDEN: {
    en: 'You do not have permission to access this resource.',
    ko: '이 리소스에 접근할 권한이 없습니다.',
  },
  NOT_FOUND: {
    en: 'The requested resource was not found.',
    ko: '요청한 리소스를 찾을 수 없습니다.',
  },
} as const;

// 앱 설정
export const APP_CONFIG = {
  VERSION: '1.0.0',
  BUILD_NUMBER: '1',
  MIN_ANDROID_VERSION: 21,
  MIN_IOS_VERSION: '12.0',
  SUPPORTED_LANGUAGES: ['en', 'ko'],
  DEFAULT_LANGUAGE: 'en',
  GOOGLE_MAPS_API_KEY: process.env.GOOGLE_MAPS_API_KEY || '',
  GOOGLE_OAUTH_CLIENT_ID: process.env.GOOGLE_OAUTH_CLIENT_ID || '',
} as const;

// 페이징 설정
export const PAGINATION = {
  DEFAULT_LIMIT: 20,
  MAX_LIMIT: 100,
  DEFAULT_OFFSET: 0,
} as const;