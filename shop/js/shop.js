/**
 * HOME K MART Supermarket JavaScript
 * H Mart 스타일의 슈퍼마켓 쇼핑몰
 */

// 전역 변수
let cart = [];
let currentSlide = 0;
let slideInterval;
let selectedStoreId = null;
let stores = [];

// DOM 로드 완료 후 실행
document.addEventListener('DOMContentLoaded', function() {
    console.log('HOME K MART 슈퍼마켓 로드됨');
    
    initializePage();
    loadStores();
    startBannerSlider();
    loadCategories();
    loadProducts();
});

// 페이지 초기화
function initializePage() {
    // 로컬 스토리지에서 장바구니 로드
    loadCartFromStorage();
    updateCartBadge();
    
    // 선택된 점포 로드
    loadSelectedStore();
    
    // 검색 입력창 엔터키 이벤트
    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                searchProducts();
            }
        });
    }
    
    // 문서 클릭 시 드롭다운 닫기
    document.addEventListener('click', function(e) {
        const storeSelector = document.querySelector('.store-selector');
        const storeDropdown = document.getElementById('storeDropdown');
        
        if (!storeSelector.contains(e.target)) {
            storeDropdown.classList.remove('open');
            document.querySelector('.current-store').classList.remove('open');
        }
    });
}

// 배너 슬라이더 시작
function startBannerSlider() {
    const slides = document.querySelectorAll('.banner-slide');
    if (slides.length <= 1) return;
    
    slideInterval = setInterval(() => {
        slides[currentSlide].classList.remove('active');
        currentSlide = (currentSlide + 1) % slides.length;
        slides[currentSlide].classList.add('active');
    }, 4000);
}

// 카테고리 로드
async function loadCategories() {
    try {
        console.log('카테고리 로드 시작');
        const response = await fetch('/homekmart/shop/api/categories_supermarket.php');
        const data = await response.json();
        
        if (data.success) {
            renderCategories(data.categories);
            console.log('카테고리 로드 성공:', data.categories.length);
        } else {
            console.error('카테고리 로드 실패:', data);
            renderFallbackCategories();
        }
    } catch (error) {
        console.error('카테고리 API 오류:', error);
        renderFallbackCategories();
    }
}

// 카테고리 렌더링
function renderCategories(categories) {
    const container = document.getElementById('categoriesGrid');
    if (!container) return;
    
    container.innerHTML = '';
    
    categories.forEach(category => {
        const categoryCard = document.createElement('div');
        categoryCard.className = 'category-card';
        categoryCard.onclick = () => filterByCategory(category.id, category.name_kr);
        
        categoryCard.innerHTML = `
            <div class="category-icon">
                <i class="${category.icon_class}" style="color: ${category.color || '#DE121C'}"></i>
            </div>
            <div class="category-name">${category.name_kr}</div>
            <div class="category-count">${category.product_count}개 상품</div>
        `;
        
        container.appendChild(categoryCard);
    });
}

// 대체 카테고리 (API 실패 시)
function renderFallbackCategories() {
    const fallbackCategories = [
        { id: 1, name_kr: '신선식품', icon_class: 'fas fa-leaf', color: '#4CAF50', product_count: 45 },
        { id: 2, name_kr: '가공식품', icon_class: 'fas fa-cookie-bite', color: '#FF9800', product_count: 78 },
        { id: 3, name_kr: '냉동식품', icon_class: 'fas fa-snowflake', color: '#2196F3', product_count: 32 },
        { id: 4, name_kr: '생활용품', icon_class: 'fas fa-home', color: '#9C27B0', product_count: 56 },
        { id: 5, name_kr: '건강/미용', icon_class: 'fas fa-heart', color: '#E91E63', product_count: 23 },
        { id: 6, name_kr: '주방용품', icon_class: 'fas fa-utensils', color: '#607D8B', product_count: 41 }
    ];
    
    renderCategories(fallbackCategories);
    console.log('대체 카테고리 로드됨');
}

// 상품 로드
async function loadProducts() {
    await Promise.all([
        loadProductsByType('deals', 'dealsGrid', 'dealsLoading'),
        loadProductsByType('new', 'newGrid', 'newLoading'),
        loadProductsByType('fresh', 'freshGrid', 'freshLoading')
    ]);
}

// 타입별 상품 로드
async function loadProductsByType(type, gridId, loadingId) {
    try {
        // 로딩 표시
        const loadingElement = document.getElementById(loadingId);
        const gridElement = document.getElementById(gridId);
        
        if (!loadingElement || !gridElement) return;
        
        // 1초 로딩 시뮬레이션
        await new Promise(resolve => setTimeout(resolve, 1000));
        
        // 점포별 상품 API 사용
        let apiUrl = `/homekmart/shop/api/store_products.php?type=${type}&limit=12`;
        if (selectedStoreId) {
            apiUrl += `&store_id=${selectedStoreId}`;
        }
        
        const response = await fetch(apiUrl);
        const data = await response.json();
        
        if (data.success && data.products.length > 0) {
            renderProducts(data.products, gridElement);
            loadingElement.style.display = 'none';
            gridElement.style.display = 'grid';
            console.log(`${type} 상품 로드 성공:`, data.products.length, `(점포: ${data.store ? data.store.name : '기본'})`);
        } else {
            // 데이터가 없으면 대체 데이터 사용
            renderFallbackProducts(type, gridElement);
            loadingElement.style.display = 'none';
            gridElement.style.display = 'grid';
            console.log(`${type} 대체 상품 로드됨`);
        }
    } catch (error) {
        console.error(`${type} 상품 로드 오류:`, error);
        renderFallbackProducts(type, document.getElementById(gridId));
        document.getElementById(loadingId).style.display = 'none';
        document.getElementById(gridId).style.display = 'grid';
    }
}

// 상품 렌더링
function renderProducts(products, container) {
    if (!container) return;
    
    container.innerHTML = '';
    
    products.forEach(product => {
        const productCard = document.createElement('div');
        productCard.className = 'product-card';
        
        const discountBadge = product.discount_rate > 0 ? 
            `<div class="discount-badge">-${product.discount_rate}%</div>` : '';
        
        const originalPrice = product.original_price && product.original_price > product.selling_price ?
            `<span class="price-original">₩${formatPrice(product.original_price)}</span>` : '';
        
        const additionalInfo = [];
        if (product.origin) additionalInfo.push(product.origin);
        if (product.weight) additionalInfo.push(product.weight);
        if (product.expiry_info) additionalInfo.push(product.expiry_info);
        
        productCard.innerHTML = `
            <div class="product-image">
                ${discountBadge}
                <i class="fas fa-image"></i>
            </div>
            <div class="product-info">
                <div class="product-category">${product.category_name}</div>
                <div class="product-name">${product.name_kr}</div>
                <div class="product-description">${product.description}</div>
                ${additionalInfo.length > 0 ? `<div class="text-xs text-gray-500 mb-2">${additionalInfo.join(' | ')}</div>` : ''}
                <div class="product-price">
                    <div>
                        <span class="price-current">₩${formatPrice(product.selling_price)}</span>
                        ${originalPrice}
                    </div>
                </div>
                <button class="add-cart-btn" onclick="addToCart(${product.id}, '${product.name_kr}', ${product.selling_price})">
                    <i class="fas fa-cart-plus"></i> 장바구니 담기
                </button>
            </div>
        `;
        
        container.appendChild(productCard);
    });
}

// 대체 상품 데이터
function renderFallbackProducts(type, container) {
    const fallbackProducts = {
        deals: [
            { id: 1, name_kr: '유기농 상추', category_name: '신선식품', selling_price: 2800, original_price: 3500, discount_rate: 20, description: '농약 없이 재배한 신선한 유기농 상추' },
            { id: 4, name_kr: '노르웨이 연어', category_name: '신선식품', selling_price: 12000, original_price: 15000, discount_rate: 20, description: '오메가3가 풍부한 노르웨이산 연어' },
            { id: 5, name_kr: '신라면', category_name: '가공식품', selling_price: 3600, original_price: 4500, discount_rate: 20, description: '매콤하고 시원한 맛의 대표 라면' },
            { id: 8, name_kr: '비비고 왕교자', category_name: '냉동식품', selling_price: 6000, original_price: 7500, discount_rate: 20, description: '고기와 야채가 듬뿍 들어간 왕교자' }
        ],
        new: [
            { id: 6, name_kr: '포카칩 오리지널', category_name: '가공식품', selling_price: 1800, description: '바삭바삭한 식감의 감자칩' },
            { id: 12, name_kr: '종합비타민', category_name: '건강/미용', selling_price: 20000, description: '하루 한 알로 건강 관리' }
        ],
        fresh: [
            { id: 1, name_kr: '유기농 상추', category_name: '신선식품', selling_price: 2800, description: '농약 없이 재배한 신선한 유기농 상추' },
            { id: 2, name_kr: '한우 등심', category_name: '신선식품', selling_price: 28000, description: '프리미엄 한우 등심' },
            { id: 3, name_kr: '제주 감귤', category_name: '신선식품', selling_price: 6500, description: '당도 높은 제주산 감귤' },
            { id: 4, name_kr: '노르웨이 연어', category_name: '신선식품', selling_price: 12000, description: '오메가3가 풍부한 노르웨이산 연어' }
        ]
    };
    
    renderProducts(fallbackProducts[type] || [], container);
}

// 가격 포맷팅
function formatPrice(price) {
    return new Intl.NumberFormat('ko-KR').format(price);
}

// 카테고리별 필터
function filterByCategory(categoryId, categoryName) {
    console.log(`카테고리 필터: ${categoryName} (${categoryId})`);
    // 실제로는 별도 페이지나 모달로 이동
    alert(`${categoryName} 카테고리 상품을 보여드릴게요!`);
}

// 상품 검색
function searchProducts() {
    const searchInput = document.getElementById('searchInput');
    const searchTerm = searchInput.value.trim();
    
    if (searchTerm) {
        console.log('검색어:', searchTerm);
        alert(`"${searchTerm}" 검색 결과를 보여드릴게요!`);
    }
}

// 장바구니 관련 함수들
function addToCart(productId, productName, price) {
    const existingItem = cart.find(item => item.id === productId);
    
    if (existingItem) {
        existingItem.quantity += 1;
    } else {
        cart.push({
            id: productId,
            name: productName,
            price: price,
            quantity: 1
        });
    }
    
    saveCartToStorage();
    updateCartBadge();
    
    // 성공 메시지
    showToast(`${productName}이(가) 장바구니에 추가되었습니다!`);
}

function updateCartBadge() {
    const badge = document.getElementById('cartBadge');
    const totalItems = cart.reduce((sum, item) => sum + item.quantity, 0);
    
    if (badge) {
        if (totalItems > 0) {
            badge.textContent = totalItems;
            badge.style.display = 'flex';
        } else {
            badge.style.display = 'none';
        }
    }
}

function saveCartToStorage() {
    localStorage.setItem('homekmart_cart', JSON.stringify(cart));
}

function loadCartFromStorage() {
    const savedCart = localStorage.getItem('homekmart_cart');
    if (savedCart) {
        cart = JSON.parse(savedCart);
    }
}

function toggleCart() {
    if (cart.length === 0) {
        alert('장바구니가 비어있습니다.');
        return;
    }
    
    const cartItems = cart.map(item => 
        `${item.name} x ${item.quantity} = ₩${formatPrice(item.price * item.quantity)}`
    ).join('\n');
    
    const totalPrice = cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);
    
    alert(`장바구니 내용:\n\n${cartItems}\n\n총 금액: ₩${formatPrice(totalPrice)}`);
}

// 로그인 관련
function toggleLogin() {
    alert('로그인 기능은 준비 중입니다!');
}

// 토스트 메시지
function showToast(message) {
    const toast = document.createElement('div');
    toast.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        background: #4CAF50;
        color: white;
        padding: 12px 20px;
        border-radius: 4px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.2);
        z-index: 1000;
        font-size: 14px;
        font-weight: 500;
    `;
    toast.textContent = message;
    
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.remove();
    }, 3000);
}

// 섹션으로 스크롤
function scrollToSection(sectionId) {
    const section = document.getElementById(sectionId);
    if (section) {
        section.scrollIntoView({ 
            behavior: 'smooth',
            block: 'start'
        });
    }
}

// 유틸리티 함수들
const utils = {
    formatPrice: formatPrice,
    showToast: showToast,
    scrollToSection: scrollToSection
};

// =============================================================================
// 점포 선택 기능
// =============================================================================

// 점포 목록 로드
async function loadStores() {
    try {
        console.log('점포 목록 로딩 중...');
        const response = await fetch('/homekmart/shop/api/stores.php');
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const data = await response.json();
        
        if (data.success) {
            stores = data.stores;
            console.log('점포 목록 로드됨:', stores.length, '개');
            renderStoreList();
        } else {
            console.error('점포 목록 로드 실패:', data.message);
            renderFallbackStores();
        }
        
    } catch (error) {
        console.error('점포 목록 로드 중 오류:', error);
        renderFallbackStores();
    }
}

// 선택된 점포 로드
async function loadSelectedStore() {
    try {
        const response = await fetch('/homekmart/shop/api/store_select.php');
        
        if (response.ok) {
            const data = await response.json();
            if (data.success && data.selected_store) {
                selectedStoreId = data.selected_store.id;
                updateCurrentStoreDisplay(data.selected_store);
            }
        }
    } catch (error) {
        console.error('선택된 점포 로드 중 오류:', error);
    }
    
    // 로컬 스토리지에서 백업
    if (!selectedStoreId) {
        const stored = localStorage.getItem('selectedStoreId');
        if (stored) {
            selectedStoreId = parseInt(stored);
            const store = stores.find(s => s.id === selectedStoreId);
            if (store) {
                updateCurrentStoreDisplay(store);
            }
        }
    }
}

// 점포 목록 렌더링
function renderStoreList() {
    const storeList = document.getElementById('storeList');
    if (!storeList) return;
    
    if (stores.length === 0) {
        storeList.innerHTML = '<div class="loading">운영중인 점포가 없습니다.</div>';
        return;
    }
    
    const storeItems = stores.map(store => `
        <div class="store-item ${selectedStoreId === store.id ? 'selected' : ''}" 
             onclick="selectStore(${store.id}, '${store.name}', '${store.address}')">
            <div class="store-item-info">
                <div class="store-item-name">${store.name}</div>
                <div class="store-item-address">${store.address || '주소 정보 없음'}</div>
            </div>
            <div class="store-item-status">
                <span class="status-badge ${store.is_active ? 'active' : 'inactive'}">
                    ${store.is_active ? '운영중' : '준비중'}
                </span>
                <span class="product-count">${store.product_count}개 상품</span>
            </div>
        </div>
    `).join('');
    
    storeList.innerHTML = storeItems;
}

// 점포 목록 백업 렌더링 (API 실패 시)
function renderFallbackStores() {
    const storeList = document.getElementById('storeList');
    if (!storeList) return;
    
    const fallbackStores = [
        {
            id: 1,
            name: 'CLARK HILLS',
            address: 'Clark Hills 지역',
            is_active: true,
            product_count: 120
        }
    ];
    
    stores = fallbackStores;
    selectedStoreId = 1;
    
    const storeItems = fallbackStores.map(store => `
        <div class="store-item selected" 
             onclick="selectStore(${store.id}, '${store.name}', '${store.address}')">
            <div class="store-item-info">
                <div class="store-item-name">${store.name}</div>
                <div class="store-item-address">${store.address}</div>
            </div>
            <div class="store-item-status">
                <span class="status-badge active">운영중</span>
                <span class="product-count">${store.product_count}개 상품</span>
            </div>
        </div>
    `).join('');
    
    storeList.innerHTML = storeItems;
    updateCurrentStoreDisplay(fallbackStores[0]);
}

// 점포 선택
async function selectStore(storeId, storeName, storeAddress) {
    try {
        console.log('점포 선택:', storeId, storeName);
        
        // 서버에 점포 선택 전송
        const response = await fetch('/homekmart/shop/api/store_select.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                store_id: storeId
            })
        });
        
        if (response.ok) {
            const data = await response.json();
            if (data.success) {
                console.log('점포 선택 성공:', data.message);
            }
        }
        
    } catch (error) {
        console.error('점포 선택 중 오류:', error);
    }
    
    // 로컬 상태 업데이트
    selectedStoreId = storeId;
    localStorage.setItem('selectedStoreId', storeId.toString());
    
    // UI 업데이트
    updateCurrentStoreDisplay({ id: storeId, name: storeName, address: storeAddress });
    updateStoreSelection();
    toggleStoreList(); // 드롭다운 닫기
    
    // 상품 목록 다시 로드
    loadProducts();
    
    // 토스트 메시지
    showToast(`${storeName}점이 선택되었습니다.`);
}

// 현재 점포 표시 업데이트
function updateCurrentStoreDisplay(store) {
    const storeNameEl = document.getElementById('currentStoreName');
    if (storeNameEl && store) {
        storeNameEl.textContent = store.name;
    }
}

// 점포 선택 상태 업데이트
function updateStoreSelection() {
    const storeItems = document.querySelectorAll('.store-item');
    storeItems.forEach(item => {
        item.classList.remove('selected');
        const onclick = item.getAttribute('onclick');
        if (onclick && onclick.includes(`selectStore(${selectedStoreId},`)) {
            item.classList.add('selected');
        }
    });
}

// 점포 목록 토글
function toggleStoreList() {
    const dropdown = document.getElementById('storeDropdown');
    const currentStore = document.querySelector('.current-store');
    
    if (dropdown.classList.contains('open')) {
        dropdown.classList.remove('open');
        currentStore.classList.remove('open');
    } else {
        dropdown.classList.add('open');
        currentStore.classList.add('open');
    }
}

// 전역 스코프에 함수들 노출
window.searchProducts = searchProducts;
window.toggleCart = toggleCart;
window.toggleLogin = toggleLogin;
window.addToCart = addToCart;
window.filterByCategory = filterByCategory;
window.scrollToSection = scrollToSection;
window.toggleStoreList = toggleStoreList;
window.selectStore = selectStore;