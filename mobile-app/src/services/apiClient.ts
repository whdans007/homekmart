import axios, { AxiosInstance, AxiosRequestConfig, AxiosResponse } from 'axios';
import AsyncStorage from '@react-native-async-storage/async-storage';
import { API_CONFIG, DEFAULT_HEADERS, CACHE_KEYS, API_STATUS_CODES } from '@/constants/api';
import { ApiResponse, ApiError, ApiRequestOptions } from '@/types/api';

class ApiClient {
  private instance: AxiosInstance;
  private authToken: string | null = null;

  constructor() {
    this.instance = axios.create({
      baseURL: API_CONFIG.BASE_URL,
      timeout: API_CONFIG.TIMEOUT,
      headers: DEFAULT_HEADERS,
    });

    this.setupInterceptors();
    this.loadAuthToken();
  }

  private setupInterceptors() {
    // Request 인터셉터
    this.instance.interceptors.request.use(
      async (config) => {
        // 인증 토큰 추가
        if (this.authToken) {
          config.headers.Authorization = `Bearer ${this.authToken}`;
        }

        // 요청 로깅 (개발 환경에서만)
        if (__DEV__) {
          console.log(`🚀 API Request: ${config.method?.toUpperCase()} ${config.url}`, {
            params: config.params,
            data: config.data,
            headers: config.headers,
          });
        }

        return config;
      },
      (error) => {
        console.error('❌ Request Error:', error);
        return Promise.reject(this.handleError(error));
      }
    );

    // Response 인터셉터
    this.instance.interceptors.response.use(
      (response: AxiosResponse) => {
        // 응답 로깅 (개발 환경에서만)
        if (__DEV__) {
          console.log(`✅ API Response: ${response.config.method?.toUpperCase()} ${response.config.url}`, {
            status: response.status,
            data: response.data,
          });
        }

        return response;
      },
      async (error) => {
        console.error('❌ Response Error:', error);

        // 401 에러 시 토큰 제거 및 로그아웃 처리
        if (error.response?.status === API_STATUS_CODES.UNAUTHORIZED) {
          await this.clearAuthToken();
          // 여기서 로그아웃 이벤트를 발생시킬 수 있습니다
        }

        return Promise.reject(this.handleError(error));
      }
    );
  }

  private async loadAuthToken() {
    try {
      const token = await AsyncStorage.getItem(CACHE_KEYS.AUTH_TOKEN);
      if (token) {
        this.authToken = token;
      }
    } catch (error) {
      console.error('토큰 로드 실패:', error);
    }
  }

  public async setAuthToken(token: string) {
    this.authToken = token;
    try {
      await AsyncStorage.setItem(CACHE_KEYS.AUTH_TOKEN, token);
    } catch (error) {
      console.error('토큰 저장 실패:', error);
    }
  }

  public async clearAuthToken() {
    this.authToken = null;
    try {
      await AsyncStorage.removeItem(CACHE_KEYS.AUTH_TOKEN);
    } catch (error) {
      console.error('토큰 제거 실패:', error);
    }
  }

  private handleError(error: any): ApiError {
    if (axios.isAxiosError(error)) {
      const status = error.response?.status;
      const message = error.response?.data?.error || error.message;
      
      return {
        message,
        status,
        code: error.code,
        details: error.response?.data,
      };
    }

    return {
      message: error.message || '알 수 없는 오류가 발생했습니다.',
    };
  }

  // GET 요청
  public async get<T = any>(
    url: string,
    params?: Record<string, any>,
    options?: ApiRequestOptions
  ): Promise<ApiResponse<T>> {
    const config: AxiosRequestConfig = {
      params,
      timeout: options?.timeout || API_CONFIG.TIMEOUT,
    };

    if (options?.cache) {
      // 캐시 로직 구현 (선택사항)
    }

    const response = await this.instance.get(url, config);
    return response.data;
  }

  // POST 요청
  public async post<T = any>(
    url: string,
    data?: any,
    options?: ApiRequestOptions
  ): Promise<ApiResponse<T>> {
    const config: AxiosRequestConfig = {
      timeout: options?.timeout || API_CONFIG.TIMEOUT,
    };

    const response = await this.instance.post(url, data, config);
    return response.data;
  }

  // PUT 요청
  public async put<T = any>(
    url: string,
    data?: any,
    options?: ApiRequestOptions
  ): Promise<ApiResponse<T>> {
    const config: AxiosRequestConfig = {
      timeout: options?.timeout || API_CONFIG.TIMEOUT,
    };

    const response = await this.instance.put(url, data, config);
    return response.data;
  }

  // DELETE 요청
  public async delete<T = any>(
    url: string,
    options?: ApiRequestOptions
  ): Promise<ApiResponse<T>> {
    const config: AxiosRequestConfig = {
      timeout: options?.timeout || API_CONFIG.TIMEOUT,
    };

    const response = await this.instance.delete(url, config);
    return response.data;
  }

  // PATCH 요청
  public async patch<T = any>(
    url: string,
    data?: any,
    options?: ApiRequestOptions
  ): Promise<ApiResponse<T>> {
    const config: AxiosRequestConfig = {
      timeout: options?.timeout || API_CONFIG.TIMEOUT,
    };

    const response = await this.instance.patch(url, data, config);
    return response.data;
  }

  // 재시도 로직이 포함된 요청
  public async requestWithRetry<T = any>(
    requestFn: () => Promise<ApiResponse<T>>,
    retries: number = API_CONFIG.RETRIES
  ): Promise<ApiResponse<T>> {
    try {
      return await requestFn();
    } catch (error) {
      if (retries > 0) {
        console.log(`🔄 API 재시도... (남은 시도: ${retries})`);
        await new Promise(resolve => setTimeout(resolve, 1000)); // 1초 대기
        return this.requestWithRetry(requestFn, retries - 1);
      }
      throw error;
    }
  }

  // 연결 상태 확인
  public async checkConnection(): Promise<boolean> {
    try {
      await this.get('/step_by_step.php', {}, { timeout: 5000 });
      return true;
    } catch (error) {
      return false;
    }
  }

  // 디버그 정보 가져오기
  public async getDebugInfo() {
    try {
      const response = await this.get('/db_schema_inspector.php');
      return response;
    } catch (error) {
      console.error('디버그 정보 가져오기 실패:', error);
      return null;
    }
  }
}

// 싱글톤 인스턴스
export const apiClient = new ApiClient();

// 유틸리티 함수들
export const apiUtils = {
  // URL 파라미터 생성
  createQueryString: (params: Record<string, any>): string => {
    const searchParams = new URLSearchParams();
    
    Object.entries(params).forEach(([key, value]) => {
      if (value !== undefined && value !== null) {
        searchParams.append(key, String(value));
      }
    });
    
    return searchParams.toString();
  },

  // 응답 유효성 검사
  validateResponse: <T>(response: ApiResponse<T>): response is ApiResponse<T> & { success: true; data: T } => {
    return response.success === true && response.data !== undefined;
  },

  // 에러 응답 확인
  isErrorResponse: (response: ApiResponse): response is ApiResponse & { success: false; error: string } => {
    return response.success === false;
  },

  // 페이징 정보 추출
  extractPagination: (response: any) => {
    return response.data?.pagination || null;
  },

  // 필리핀 페소 포맷팅
  formatPhpCurrency: (amount: number): string => {
    return `₱${amount.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
  },

  // 날짜 포맷팅 (필리핀 시간대)
  formatPhilippineDate: (dateString: string): string => {
    const date = new Date(dateString);
    return date.toLocaleString('en-PH', {
      timeZone: 'Asia/Manila',
      year: 'numeric',
      month: 'short',
      day: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    });
  },
};

export default apiClient;