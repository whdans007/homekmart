// API 타입 정의
export interface ApiResponse<T = any> {
  success: boolean;
  message: string;
  data?: T;
  error?: string;
  timestamp: string;
}

// 사용자 관련 타입
export interface User {
  id: number;
  name: string;
  email: string;
  phone_number?: string;
  google_id?: string;
  profile_picture?: string;
  created_at: string;
}

export interface UserProfile extends User {
  default_address_id?: number;
  preferred_language: 'en' | 'ko';
  notification_settings: {
    order_updates: boolean;
    promotions: boolean;
    delivery_notifications: boolean;
  };
}

// 상품 관련 타입
export interface Product {
  id: number;
  name: string;
  description: string;
  barcode?: string;
  category_id?: number;
  brand_id?: number;
  price_info: {
    amount: number;
    currency: 'PHP';
    formatted: string;
    delivery_surcharge: number;
    total_with_delivery: number;
    note?: string;
  };
  delivery_info: {
    available_for_delivery: boolean;
    estimated_delivery_time: string;
    delivery_zones: string[];
    special_handling: boolean;
  };
  stock_info: {
    quantity: number;
    in_stock: boolean;
    stock_status: 'in_stock' | 'low_stock' | 'out_of_stock';
    max_order_quantity: number;
  };
}

export interface ProductsResponse {
  products: Product[];
  count: number;
  total_count: number;
  pagination: {
    limit: number;
    offset: number;
    has_more: boolean;
    total_pages: number;
    current_page: number;
  };
  filters_applied: {
    category_id: number;
    store_id: number;
    search: string;
    price_range: [number, number] | null;
    available_only: boolean;
    delivery_zone: string;
  };
  currency: {
    code: 'PHP';
    symbol: '₱';
    name: string;
  };
}

// 카테고리 타입
export interface Category {
  id: number;
  name: string;
  description: string;
  available_for_delivery: boolean;
}

export interface CategoriesResponse {
  categories: Category[];
  count: number;
}

// 주소 관련 타입
export interface DeliveryAddress {
  id: number;
  user_id: number;
  recipient_name: string;
  phone_number: string;
  street_address: string;
  barangay: string;
  city: string;
  province: string;
  postal_code: string;
  landmark?: string;
  delivery_instructions?: string;
  is_default: boolean;
  full_address: string;
  created_at: string;
}

export interface AddressesResponse {
  addresses: DeliveryAddress[];
  count: number;
}

// 주문 관련 타입
export interface OrderItem {
  product_id: number;
  quantity: number;
  unit_price: number;
  total_price: number;
  store_id: number;
  product?: Product; // 포함된 제품 정보
}

export interface Order {
  id: number;
  user_id: number;
  order_number: string;
  status: 'pending' | 'confirmed' | 'preparing' | 'delivering' | 'delivered' | 'cancelled';
  total_amount: number;
  payment_method: 'cod';
  delivery_fee: number;
  currency: 'PHP';
  formatted_total: string;
  created_at: string;
  delivery_address_id: number;
  delivery_address?: DeliveryAddress;
  items?: OrderItem[];
  notes?: string;
}

export interface OrdersResponse {
  orders: Order[];
  count: number;
  currency: 'PHP';
}

export interface CreateOrderRequest {
  user_id: number;
  delivery_address_id: number;
  payment_method: 'cod';
  notes?: string;
  items: {
    product_id: number;
    quantity: number;
    store_id?: number;
  }[];
}

// 장바구니 타입
export interface CartItem {
  id: number;
  user_id: number;
  product_id: number;
  quantity: number;
  store_id: number;
  added_at: string;
  product?: Product;
}

export interface CartResponse {
  items: CartItem[];
  count: number;
  total_amount: number;
  currency: 'PHP';
  formatted_total: string;
}

export interface AddToCartRequest {
  user_id: number;
  product_id: number;
  quantity: number;
  store_id: number;
}

// 배달 구역 타입
export interface DeliveryZone {
  id: number;
  zone_name: string;
  zone_type: string;
  delivery_fee: number;
  minimum_order: number;
  delivery_time_min: number;
  delivery_time_max: number;
  currency: 'PHP';
  formatted_fee: string;
  formatted_minimum: string;
  coverage_areas: string[];
  is_active: boolean;
}

export interface DeliveryZonesResponse {
  zones: DeliveryZone[];
  count: number;
}

export interface DeliveryCoverageResponse {
  is_deliverable: boolean;
  zone_id?: number;
  zone_name?: string;
  delivery_fee?: number;
  minimum_order?: number;
  estimated_time?: string;
  currency?: 'PHP';
  alternative_zones?: DeliveryZone[];
}

// 위치 검색 타입
export interface LocationSearchResult {
  id: string;
  name: string;
  type: 'barangay' | 'city' | 'province';
  full_address: string;
  delivery_available: boolean;
  coordinates: {
    lat: number;
    lng: number;
  };
  city?: string;
  province?: string;
  region?: string;
}

export interface LocationSearchResponse {
  results: LocationSearchResult[];
  count: number;
  query: string;
  type_filter: string;
  search_suggestions: string[];
}

// 인증 관련 타입
export interface GoogleSignInResponse {
  user: UserProfile;
  token: string;
  expires_at: string;
}

export interface LoginRequest {
  google_token: string;
  device_info?: {
    platform: 'ios' | 'android';
    device_id: string;
    app_version: string;
  };
}

// API 요청 옵션
export interface ApiRequestOptions {
  timeout?: number;
  retries?: number;
  cache?: boolean;
}

// 에러 타입
export interface ApiError {
  message: string;
  status?: number;
  code?: string;
  details?: any;
}

// 필터 옵션
export interface ProductFilters {
  category_id?: number;
  search?: string;
  min_price?: number;
  max_price?: number;
  available_only?: boolean;
  delivery_zone?: string;
  store_id?: number;
  limit?: number;
  offset?: number;
}

export interface OrderFilters {
  user_id: number;
  status?: Order['status'];
  limit?: number;
  offset?: number;
}