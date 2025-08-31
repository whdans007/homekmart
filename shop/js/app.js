/**
 * HOME K MART Shop Vue.js Application
 * 메인 Vue 애플리케이션 파일
 */

const { createApp } = Vue;

// API 기본 URL (실제 API 사용)
const API_BASE_URL = '/homekmart/shop/api';

// 전역 함수들
const utils = {
    // 가격 포맷팅 (한국 원화)
    formatPrice(price) {
        return new Intl.NumberFormat('ko-KR').format(price);
    },
    
    // 날짜 포맷팅
    formatDate(date) {
        return new Intl.DateTimeFormat('ko-KR').format(new Date(date));
    },
    
    // API 호출 헬퍼
    async apiCall(endpoint, options = {}) {
        const url = `${API_BASE_URL}${endpoint}`;
        const config = {
            headers: {
                'Content-Type': 'application/json',
                ...options.headers
            },
            ...options
        };
        
        try {
            const response = await fetch(url, config);
            const data = await response.json();
            
            if (!response.ok) {
                throw new Error(data.message || '서버 오류가 발생했습니다.');
            }
            
            return data;
        } catch (error) {
            console.error('API Error:', error);
            throw error;
        }
    },
    
    // 로컬 스토리지 헬퍼
    storage: {
        get(key) {
            try {
                const item = localStorage.getItem(key);
                return item ? JSON.parse(item) : null;
            } catch {
                return null;
            }
        },
        
        set(key, value) {
            try {
                localStorage.setItem(key, JSON.stringify(value));
            } catch (error) {
                console.error('Storage Error:', error);
            }
        },
        
        remove(key) {
            localStorage.removeItem(key);
        }
    },
    
    // 알림 표시
    showToast(message, type = 'info') {
        // 간단한 토스트 구현 (나중에 라이브러리로 교체 가능)
        const toast = document.createElement('div');
        toast.className = `fixed top-4 right-4 px-6 py-3 rounded-lg shadow-lg z-50 text-white ${
            type === 'success' ? 'bg-green-500' : 
            type === 'error' ? 'bg-red-500' : 
            'bg-blue-500'
        }`;
        toast.textContent = message;
        
        document.body.appendChild(toast);
        
        setTimeout(() => {
            toast.remove();
        }, 3000);
    }
};

// Vue.js 애플리케이션
const App = createApp({
    data() {
        return {
            // 앱 상태
            loading: false,
            searchQuery: '',
            sortBy: 'name',
            
            // 사용자 관련
            user: null,
            isLoggedIn: false,
            showLogin: false,
            showRegister: false,
            
            // 상품 데이터
            products: [],
            categories: [],
            currentPage: 1,
            totalPages: 1,
            
            // 장바구니
            cart: [],
            showCart: false,
            
            // 로그인 폼
            loginForm: {
                email: '',
                password: ''
            },
            
            // 회원가입 폼
            registerForm: {
                name: '',
                email: '',
                password: '',
                password_confirm: '',
                phone: '',
                marketing_agree: false
            }
        }
    },
    
    computed: {
        cartCount() {
            return this.cart.reduce((sum, item) => sum + item.quantity, 0);
        },
        
        cartTotal() {
            return this.cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);
        }
    },
    
    methods: {
        // 유틸리티 함수들
        formatPrice: utils.formatPrice,
        
        // 초기화
        async init() {
            await this.loadCategories();
            await this.loadProducts();
            await this.loadCart();
            this.checkLoginStatus();
        },
        
        // 카테고리 로드
        async loadCategories() {
            try {
                const response = await utils.apiCall('/categories.php');
                this.categories = response.categories || [];
                console.log('카테고리 로드 성공:', this.categories);
            } catch (error) {
                console.error('카테고리 로드 실패:', error);
                // 실패 시 하드코딩된 데이터 사용
                this.categories = [
                    { id: 1, name_kr: '전자제품', icon_class: 'fas fa-mobile-alt' },
                    { id: 2, name_kr: '의류', icon_class: 'fas fa-tshirt' },
                    { id: 3, name_kr: '생활용품', icon_class: 'fas fa-home' }
                ];
                console.log('카테고리 하드코딩 데이터 사용:', this.categories);
            }
        },
        
        // 상품 로드 (실제 API 호출)
        async loadProducts(params = {}) {
            this.loading = true;
            
            try {
                // API 호출 파라미터 구성
                let url = '/products.php';
                const queryParams = [];
                
                if (params.search) {
                    queryParams.push(`search=${encodeURIComponent(params.search)}`);
                }
                if (params.category_id) {
                    queryParams.push(`category_id=${params.category_id}`);
                }
                if (this.sortBy) {
                    queryParams.push(`sort=${this.sortBy}`);
                }
                
                if (queryParams.length > 0) {
                    url += '?' + queryParams.join('&');
                }
                
                const response = await utils.apiCall(url);
                
                if (response.success) {
                    this.products = response.products || [];
                    this.currentPage = response.current_page || 1;
                    this.totalPages = response.total_pages || 1;
                    console.log('상품 로드 성공:', this.products.length + '개 상품');
                } else {
                    throw new Error(response.message || '상품 로드 실패');
                }
                
            } catch (error) {
                console.error('상품 로드 실패:', error);
                utils.showToast('상품을 불러오는데 실패했습니다.', 'error');
                
                // 실패 시 빈 배열로 설정
                this.products = [];
                this.currentPage = 1;
                this.totalPages = 1;
            } finally {
                this.loading = false;
            }
        },
        
        // 상품 검색
        async searchProducts() {
            if (!this.searchQuery.trim()) {
                await this.loadProducts();
                return;
            }
            
            await this.loadProducts({ search: this.searchQuery });
        },
        
        // 카테고리별 필터
        async filterByCategory(categoryId) {
            await this.loadProducts({ category_id: categoryId });
        },
        
        // 정렬
        async sortProducts() {
            await this.loadProducts();
        },
        
        // 상품 상세 보기 (추후 구현)
        viewProduct(productId) {
            // TODO: 모달 또는 새 페이지로 상품 상세 표시
            console.log('상품 상세:', productId);
            utils.showToast('상품 상세 페이지는 준비 중입니다.');
        },
        
        // 장바구니 관련
        async loadCart() {
            try {
                const response = await utils.apiCall('/cart_standalone.php');
                if (response.success) {
                    this.cart = response.cart || [];
                    console.log('장바구니 로드 성공:', this.cart.length + '개 상품');
                } else {
                    throw new Error(response.message || '장바구니 로드 실패');
                }
            } catch (error) {
                console.error('장바구니 로드 실패:', error);
                // 세션 기반이므로 실패해도 빈 배열로 설정
                this.cart = [];
            }
        },
        
        async addToCart(product) {
            try {
                const response = await utils.apiCall('/cart_standalone.php?action=add', {
                    method: 'POST',
                    body: JSON.stringify({
                        product_id: product.id,
                        quantity: 1
                    })
                });
                
                if (response.success) {
                    await this.loadCart();
                    utils.showToast('장바구니에 추가되었습니다.', 'success');
                } else {
                    throw new Error(response.message || '장바구니 추가 실패');
                }
            } catch (error) {
                console.error('장바구니 추가 실패:', error);
                utils.showToast('장바구니 추가에 실패했습니다.', 'error');
            }
        },
        
        async updateQuantity(productId, quantity) {
            if (quantity <= 0) {
                await this.removeFromCart(productId);
                return;
            }
            
            try {
                const response = await utils.apiCall('/cart_standalone.php', {
                    method: 'PUT',
                    body: JSON.stringify({
                        product_id: productId,
                        quantity: quantity
                    })
                });
                
                if (response.success) {
                    await this.loadCart();
                } else {
                    throw new Error(response.message || '수량 변경 실패');
                }
            } catch (error) {
                console.error('장바구니 업데이트 실패:', error);
                utils.showToast('수량 변경에 실패했습니다.', 'error');
            }
        },
        
        async removeFromCart(productId) {
            try {
                const response = await utils.apiCall('/cart_standalone.php', {
                    method: 'DELETE',
                    body: JSON.stringify({
                        product_id: productId
                    })
                });
                
                if (response.success) {
                    await this.loadCart();
                    utils.showToast('상품이 제거되었습니다.', 'success');
                } else {
                    throw new Error(response.message || '상품 제거 실패');
                }
            } catch (error) {
                console.error('장바구니 제거 실패:', error);
                utils.showToast('상품 제거에 실패했습니다.', 'error');
            }
        },
        
        toggleCart() {
            this.showCart = !this.showCart;
        },
        
        // 주문하기
        checkout() {
            if (!this.isLoggedIn) {
                this.showLogin = true;
                this.showCart = false;
                utils.showToast('주문하려면 로그인이 필요합니다.');
                return;
            }
            
            if (this.cart.length === 0) {
                utils.showToast('장바구니가 비어있습니다.');
                return;
            }
            
            // TODO: 주문 페이지로 이동
            utils.showToast('주문 기능은 준비 중입니다.');
        },
        
        // 로그인 관련
        async checkLoginStatus() {
            try {
                const response = await utils.apiCall('/auth.php?action=check');
                if (response.success && response.logged_in !== false && response.user) {
                    this.user = response.user;
                    this.isLoggedIn = true;
                    console.log('로그인 상태 확인:', this.user.name + '님');
                } else {
                    this.user = null;
                    this.isLoggedIn = false;
                }
            } catch (error) {
                console.error('로그인 상태 확인 실패:', error);
                this.user = null;
                this.isLoggedIn = false;
            }
        },
        
        async login() {
            if (!this.loginForm.email || !this.loginForm.password) {
                utils.showToast('이메일과 비밀번호를 입력해주세요.', 'error');
                return;
            }
            
            try {
                const response = await utils.apiCall('/auth.php?action=login', {
                    method: 'POST',
                    body: JSON.stringify({
                        email: this.loginForm.email,
                        password: this.loginForm.password
                    })
                });
                
                if (response.success && response.user) {
                    this.user = response.user;
                    this.isLoggedIn = true;
                    this.showLogin = false;
                    
                    // 로그인 폼 초기화
                    this.loginForm.email = '';
                    this.loginForm.password = '';
                    
                    utils.showToast('로그인되었습니다.', 'success');
                    
                    // 로그인 후 장바구니 다시 로드
                    await this.loadCart();
                } else {
                    throw new Error(response.message || '로그인 실패');
                }
                
            } catch (error) {
                console.error('로그인 실패:', error);
                utils.showToast(error.message || '로그인에 실패했습니다.', 'error');
            }
        },
        
        async register() {
            // 입력 검증
            if (!this.registerForm.name || !this.registerForm.email || 
                !this.registerForm.password || !this.registerForm.password_confirm) {
                utils.showToast('모든 필수 항목을 입력해주세요.', 'error');
                return;
            }
            
            if (this.registerForm.password !== this.registerForm.password_confirm) {
                utils.showToast('비밀번호가 일치하지 않습니다.', 'error');
                return;
            }
            
            try {
                const response = await utils.apiCall('/auth.php?action=register', {
                    method: 'POST',
                    body: JSON.stringify(this.registerForm)
                });
                
                if (response.success && response.user) {
                    this.user = response.user;
                    this.isLoggedIn = true;
                    this.showRegister = false;
                    
                    // 회원가입 폼 초기화
                    this.registerForm = {
                        name: '',
                        email: '',
                        password: '',
                        password_confirm: '',
                        phone: '',
                        marketing_agree: false
                    };
                    
                    utils.showToast('회원가입이 완료되었습니다.', 'success');
                    
                    // 자동 로그인 후 장바구니 로드
                    await this.loadCart();
                } else {
                    throw new Error(response.message || '회원가입 실패');
                }
                
            } catch (error) {
                console.error('회원가입 실패:', error);
                utils.showToast(error.message || '회원가입에 실패했습니다.', 'error');
            }
        },
        
        async logout() {
            try {
                const response = await utils.apiCall('/auth.php?action=logout', {
                    method: 'POST'
                });
                
                this.user = null;
                this.isLoggedIn = false;
                
                utils.showToast('로그아웃되었습니다.');
                
                // 로그아웃 후 장바구니 다시 로드 (세션 기반이므로)
                await this.loadCart();
                
            } catch (error) {
                console.error('로그아웃 실패:', error);
                // 실패해도 클라이언트 상태는 초기화
                this.user = null;
                this.isLoggedIn = false;
                utils.showToast('로그아웃되었습니다.');
            }
        },
        
        // 스크롤 관련
        scrollToProducts() {
            document.getElementById('products').scrollIntoView({ 
                behavior: 'smooth' 
            });
        }
    },
    
    // 컴포넌트 마운트 시
    async mounted() {
        console.log('Vue 앱이 마운트되었습니다');
        await this.init();
        console.log('초기화 완료. 카테고리:', this.categories.length, '상품:', this.products.length);
    }
});

// Vue 앱 마운트
App.mount('#app');