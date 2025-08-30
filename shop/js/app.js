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
                const response = await utils.apiCall('/categories_fake.php');
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
        
        // 상품 로드 (임시로 fake 데이터 사용)
        async loadProducts(params = {}) {
            this.loading = true;
            
            try {
                // 임시 fake 데이터
                await new Promise(resolve => setTimeout(resolve, 500)); // 로딩 시뮬레이션
                
                this.products = [
                    {
                        id: 1,
                        name_kr: '스마트폰 갤럭시',
                        name_en: 'Galaxy Smartphone',
                        category_name: '전자제품',
                        selling_price: 899000,
                        cost_price: 750000,
                        image: null,
                        description: '최신 스마트폰으로 뛰어난 성능을 제공합니다',
                        quantity: 15
                    },
                    {
                        id: 2,
                        name_kr: '캐주얼 티셔츠',
                        name_en: 'Casual T-shirt',
                        category_name: '의류',
                        selling_price: 29000,
                        cost_price: 18000,
                        image: null,
                        description: '편안하고 스타일리시한 티셔츠입니다',
                        quantity: 50
                    },
                    {
                        id: 3,
                        name_kr: '스테인리스 텀블러',
                        name_en: 'Stainless Tumbler',
                        category_name: '생활용품',
                        selling_price: 25000,
                        cost_price: 15000,
                        image: null,
                        description: '보온보냉이 우수한 스테인리스 텀블러입니다',
                        quantity: 30
                    },
                    {
                        id: 4,
                        name_kr: '유기농 사과',
                        name_en: 'Organic Apple',
                        category_name: '식품',
                        selling_price: 8000,
                        cost_price: 5000,
                        image: null,
                        description: '당도 높은 유기농 사과입니다',
                        quantity: 100
                    },
                    {
                        id: 5,
                        name_kr: '수분크림',
                        name_en: 'Moisturizing Cream',
                        category_name: '뷰티',
                        selling_price: 35000,
                        cost_price: 22000,
                        image: null,
                        description: '피부에 수분과 영양을 공급하는 크림입니다',
                        quantity: 25
                    },
                    {
                        id: 6,
                        name_kr: '요가 매트',
                        name_en: 'Yoga Mat',
                        category_name: '스포츠',
                        selling_price: 45000,
                        cost_price: 30000,
                        image: null,
                        description: '미끄럼방지 기능이 있는 요가 매트입니다',
                        quantity: 20
                    }
                ];
                
                this.currentPage = 1;
                this.totalPages = 1;
            } catch (error) {
                console.error('상품 로드 실패:', error);
                utils.showToast('상품을 불러오는데 실패했습니다.', 'error');
                this.products = [];
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
                const response = await utils.apiCall('/cart');
                this.cart = response.cart;
            } catch (error) {
                console.error('장바구니 로드 실패:', error);
                // 세션 기반이므로 실패해도 계속 진행
            }
        },
        
        async addToCart(product) {
            try {
                await utils.apiCall('/cart/add', {
                    method: 'POST',
                    body: JSON.stringify({
                        product_id: product.id,
                        quantity: 1
                    })
                });
                
                await this.loadCart();
                utils.showToast('장바구니에 추가되었습니다.', 'success');
            } catch (error) {
                console.error('장바구니 추가 실패:', error);
                utils.showToast('장바구니 추가에 실패했습니다.', 'error');
            }
        },
        
        async updateQuantity(productId, quantity) {
            try {
                await utils.apiCall('/cart', {
                    method: 'PUT',
                    body: JSON.stringify({
                        product_id: productId,
                        quantity: quantity
                    })
                });
                
                await this.loadCart();
            } catch (error) {
                console.error('장바구니 업데이트 실패:', error);
                utils.showToast('수량 변경에 실패했습니다.', 'error');
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
        checkLoginStatus() {
            const user = utils.storage.get('user');
            if (user && user.token) {
                this.user = user;
                this.isLoggedIn = true;
            }
        },
        
        async login() {
            if (!this.loginForm.email || !this.loginForm.password) {
                utils.showToast('이메일과 비밀번호를 입력해주세요.', 'error');
                return;
            }
            
            try {
                // TODO: 실제 로그인 API 호출
                // const response = await utils.apiCall('/auth/login', {
                //     method: 'POST',
                //     body: JSON.stringify(this.loginForm)
                // });
                
                // 임시 로그인 (개발용)
                const user = {
                    id: 1,
                    name: '홍길동',
                    email: this.loginForm.email,
                    token: 'temp_token'
                };
                
                this.user = user;
                this.isLoggedIn = true;
                this.showLogin = false;
                
                utils.storage.set('user', user);
                utils.showToast('로그인되었습니다.', 'success');
                
                // 로그인 후 장바구니 다시 로드
                await this.loadCart();
                
            } catch (error) {
                console.error('로그인 실패:', error);
                utils.showToast('로그인에 실패했습니다.', 'error');
            }
        },
        
        logout() {
            this.user = null;
            this.isLoggedIn = false;
            this.cart = [];
            
            utils.storage.remove('user');
            utils.showToast('로그아웃되었습니다.');
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