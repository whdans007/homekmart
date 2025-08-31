<?php
// HOME K MART 온라인 쇼핑몰 메인 페이지
require_once '../config/db_config.php';

// 기본 설정
$site_title = "HOME K MART - 온라인 쇼핑몰";
$api_base_url = "/homekmart/shop/api";
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="HOME K MART 온라인 쇼핑몰에서 다양한 상품을 만나보세요">
    <title><?= $site_title ?></title>
    
    <!-- CSS Frameworks -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#2563eb',
                        secondary: '#64748b'
                    }
                }
            }
        }
    </script>
    
    <!-- Vue.js 3 -->
    <script src="https://unpkg.com/vue@3/dist/vue.global.js"></script>
    
    <!-- FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- PWA -->
    <link rel="manifest" href="../mobile/manifest.json">
    <meta name="theme-color" content="#2563eb">
    
    <style>
        [v-cloak] { display: none; }
    </style>
</head>
<body class="bg-gray-50">
    <div id="app" v-cloak>
        <!-- Header -->
        <header class="bg-white shadow-md sticky top-0 z-50">
            <div class="container mx-auto px-4">
                <nav class="flex items-center justify-between py-4">
                    <!-- Logo -->
                    <div class="flex items-center space-x-2">
                        <i class="fas fa-store text-2xl text-primary"></i>
                        <h1 class="text-xl font-bold text-gray-800">HOME K MART</h1>
                    </div>
                    
                    <!-- Search -->
                    <div class="hidden md:flex flex-1 max-w-md mx-8">
                        <div class="relative w-full">
                            <input 
                                type="text" 
                                placeholder="상품을 검색해보세요..." 
                                class="w-full pl-4 pr-10 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary"
                                v-model="searchQuery"
                                @keyup.enter="searchProducts"
                            >
                            <button @click="searchProducts" class="absolute right-2 top-2 text-gray-500 hover:text-primary">
                                <i class="fas fa-search"></i>
                            </button>
                        </div>
                    </div>
                    
                    <!-- Navigation -->
                    <div class="flex items-center space-x-4">
                        <button @click="toggleCart" class="relative p-2 text-gray-700 hover:text-primary">
                            <i class="fas fa-shopping-cart text-xl"></i>
                            <span v-if="cartCount > 0" class="absolute -top-1 -right-1 bg-red-500 text-white text-xs rounded-full w-5 h-5 flex items-center justify-center">
                                {{ cartCount }}
                            </span>
                        </button>
                        
                        <button v-if="!isLoggedIn" @click="showLogin = true" class="px-4 py-2 text-primary hover:bg-primary hover:text-white border border-primary rounded-lg transition">
                            로그인
                        </button>
                        
                        <div v-else class="flex items-center space-x-2">
                            <span class="text-gray-700">{{ user.name }}님</span>
                            <button @click="logout" class="text-gray-500 hover:text-red-500">
                                <i class="fas fa-sign-out-alt"></i>
                            </button>
                        </div>
                    </div>
                </nav>
                
                <!-- Mobile Search -->
                <div class="md:hidden pb-4">
                    <div class="relative">
                        <input 
                            type="text" 
                            placeholder="상품을 검색해보세요..." 
                            class="w-full pl-4 pr-10 py-2 border border-gray-300 rounded-lg"
                            v-model="searchQuery"
                            @keyup.enter="searchProducts"
                        >
                        <button @click="searchProducts" class="absolute right-2 top-2 text-gray-500">
                            <i class="fas fa-search"></i>
                        </button>
                    </div>
                </div>
            </div>
        </header>
        
        <!-- Main Content -->
        <main class="container mx-auto px-4 py-8">
            <!-- Hero Section -->
            <section class="bg-gradient-to-r from-primary to-blue-600 text-white rounded-lg p-8 mb-8">
                <div class="text-center">
                    <h2 class="text-3xl font-bold mb-4">HOME K MART에 오신 것을 환영합니다!</h2>
                    <p class="text-lg mb-6">최고의 상품을 최저가로 만나보세요</p>
                    <button @click="scrollToProducts" class="bg-white text-primary px-6 py-3 rounded-lg font-semibold hover:bg-gray-100 transition">
                        상품 보러가기
                    </button>
                </div>
            </section>
            
            <!-- Categories -->
            <section class="mb-8">
                <h3 class="text-2xl font-bold mb-4">카테고리</h3>
                <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4">
                    <div 
                        v-for="category in categories" 
                        :key="category.id"
                        @click="filterByCategory(category.id)"
                        class="bg-white p-4 rounded-lg shadow hover:shadow-md cursor-pointer transition text-center"
                    >
                        <i :class="category.icon" class="text-2xl text-primary mb-2"></i>
                        <p class="font-medium">{{ category.name_kr }}</p>
                    </div>
                </div>
            </section>
            
            <!-- Products -->
            <section id="products">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-2xl font-bold">상품 목록</h3>
                    <select v-model="sortBy" @change="sortProducts" class="px-4 py-2 border rounded-lg">
                        <option value="name">상품명순</option>
                        <option value="price_asc">가격 낮은순</option>
                        <option value="price_desc">가격 높은순</option>
                    </select>
                </div>
                
                <div v-if="loading" class="text-center py-8">
                    <i class="fas fa-spinner fa-spin text-2xl text-primary"></i>
                    <p class="mt-2">상품을 불러오는 중...</p>
                </div>
                
                <div v-else-if="products.length === 0" class="text-center py-8">
                    <i class="fas fa-box-open text-4xl text-gray-400 mb-4"></i>
                    <p class="text-gray-500">등록된 상품이 없습니다.</p>
                </div>
                
                <div v-else class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
                    <div 
                        v-for="product in products" 
                        :key="product.id"
                        class="bg-white rounded-lg shadow hover:shadow-lg transition cursor-pointer"
                        @click="viewProduct(product.id)"
                    >
                        <img 
                            :src="product.image || 'https://via.placeholder.com/300x200?text=No+Image'" 
                            :alt="product.name_kr"
                            class="w-full h-48 object-cover rounded-t-lg"
                        >
                        <div class="p-4">
                            <h4 class="font-semibold text-lg mb-2 line-clamp-2">{{ product.name_kr }}</h4>
                            <p class="text-sm text-gray-600 mb-2 line-clamp-1">{{ product.name_en }}</p>
                            <div class="flex justify-between items-center">
                                <span class="text-xl font-bold text-primary">
                                    {{ formatPrice(product.selling_price) }}원
                                </span>
                                <button 
                                    @click.stop="addToCart(product)"
                                    class="bg-primary text-white px-4 py-2 rounded hover:bg-blue-600 transition text-sm"
                                >
                                    <i class="fas fa-cart-plus mr-1"></i>
                                    담기
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </main>
        
        <!-- Footer -->
        <footer class="bg-gray-800 text-white py-8 mt-16">
            <div class="container mx-auto px-4 text-center">
                <h3 class="text-xl font-bold mb-4">HOME K MART</h3>
                <p class="text-gray-400 mb-4">최고의 쇼핑 경험을 제공하는 온라인 쇼핑몰</p>
                <div class="flex justify-center space-x-4 text-sm text-gray-400">
                    <a href="#" class="hover:text-white">이용약관</a>
                    <a href="#" class="hover:text-white">개인정보처리방침</a>
                    <a href="#" class="hover:text-white">고객센터</a>
                </div>
            </div>
        </footer>
        
        <!-- Cart Sidebar -->
        <div v-if="showCart" class="fixed inset-0 z-50 overflow-hidden" @click.self="showCart = false">
            <div class="absolute right-0 top-0 h-full w-80 bg-white shadow-xl p-6">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-bold">장바구니</h3>
                    <button @click="showCart = false" class="text-gray-500 hover:text-gray-700">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                
                <div v-if="cart.length === 0" class="text-center py-8">
                    <i class="fas fa-shopping-cart text-4xl text-gray-400 mb-4"></i>
                    <p class="text-gray-500">장바구니가 비어있습니다.</p>
                </div>
                
                <div v-else>
                    <div v-for="item in cart" :key="item.id" class="flex items-center space-x-4 mb-4 p-3 bg-gray-50 rounded">
                        <img :src="item.image || 'https://via.placeholder.com/60'" class="w-15 h-15 object-cover rounded">
                        <div class="flex-1">
                            <h4 class="font-medium text-sm">{{ item.name_kr }}</h4>
                            <p class="text-primary font-bold">{{ formatPrice(item.price) }}원</p>
                        </div>
                        <div class="flex items-center space-x-2">
                            <button @click="updateQuantity(item.id, item.quantity - 1)" class="w-6 h-6 rounded-full bg-gray-200 flex items-center justify-center">-</button>
                            <span class="w-8 text-center">{{ item.quantity }}</span>
                            <button @click="updateQuantity(item.id, item.quantity + 1)" class="w-6 h-6 rounded-full bg-gray-200 flex items-center justify-center">+</button>
                        </div>
                    </div>
                    
                    <div class="border-t pt-4">
                        <div class="flex justify-between mb-4">
                            <span class="font-bold">총 금액:</span>
                            <span class="text-xl font-bold text-primary">{{ formatPrice(cartTotal) }}원</span>
                        </div>
                        <button 
                            @click="checkout" 
                            class="w-full bg-primary text-white py-3 rounded-lg font-semibold hover:bg-blue-600 transition"
                        >
                            주문하기
                        </button>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Login Modal -->
        <div v-if="showLogin" class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50" @click.self="showLogin = false">
            <div class="bg-white p-6 rounded-lg w-80">
                <h3 class="text-xl font-bold mb-4">로그인</h3>
                <form @submit.prevent="login">
                    <input 
                        type="email" 
                        placeholder="이메일" 
                        v-model="loginForm.email"
                        class="w-full p-3 border rounded mb-3"
                        required
                    >
                    <input 
                        type="password" 
                        placeholder="비밀번호" 
                        v-model="loginForm.password"
                        class="w-full p-3 border rounded mb-4"
                        required
                    >
                    <button type="submit" class="w-full bg-primary text-white py-3 rounded font-semibold">
                        로그인
                    </button>
                </form>
                <p class="text-center mt-4 text-sm">
                    계정이 없으신가요? <a href="#" @click="showRegister = true; showLogin = false" class="text-primary">회원가입</a>
                </p>
            </div>
        </div>
        
        <!-- Register Modal -->
        <div v-if="showRegister" class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50" @click.self="showRegister = false">
            <div class="bg-white p-6 rounded-lg w-80 max-h-screen overflow-y-auto">
                <h3 class="text-xl font-bold mb-4">회원가입</h3>
                <form @submit.prevent="register">
                    <input 
                        type="text" 
                        placeholder="이름" 
                        v-model="registerForm.name"
                        class="w-full p-3 border rounded mb-3"
                        required
                    >
                    <input 
                        type="email" 
                        placeholder="이메일" 
                        v-model="registerForm.email"
                        class="w-full p-3 border rounded mb-3"
                        required
                    >
                    <input 
                        type="password" 
                        placeholder="비밀번호 (6자 이상)" 
                        v-model="registerForm.password"
                        class="w-full p-3 border rounded mb-3"
                        required
                        minlength="6"
                    >
                    <input 
                        type="password" 
                        placeholder="비밀번호 확인" 
                        v-model="registerForm.password_confirm"
                        class="w-full p-3 border rounded mb-3"
                        required
                    >
                    <input 
                        type="tel" 
                        placeholder="휴대폰 번호 (선택사항)" 
                        v-model="registerForm.phone"
                        class="w-full p-3 border rounded mb-4"
                    >
                    <label class="flex items-center mb-4">
                        <input 
                            type="checkbox" 
                            v-model="registerForm.marketing_agree"
                            class="mr-2"
                        >
                        <span class="text-sm">마케팅 정보 수신 동의 (선택)</span>
                    </label>
                    <button type="submit" class="w-full bg-primary text-white py-3 rounded font-semibold">
                        회원가입
                    </button>
                </form>
                <p class="text-center mt-4 text-sm">
                    이미 계정이 있으신가요? <a href="#" @click="showLogin = true; showRegister = false" class="text-primary">로그인</a>
                </p>
            </div>
        </div>
    </div>

    <!-- Vue.js App Script -->
    <script src="js/app.js"></script>
    
    <!-- Debug Script -->
    <script>
        console.log('Vue 로드 확인:', typeof Vue);
        console.log('페이지 로드 완료');
        
        // 5초 후에 Vue 앱 상태 확인
        setTimeout(() => {
            console.log('Vue 앱 확인:', document.getElementById('app'));
            const vueApp = document.getElementById('app');
            if (vueApp) {
                console.log('Vue 앱 내용:', vueApp.innerHTML.substring(0, 200));
            }
        }, 5000);
    </script>
    
    <!-- Service Worker for PWA -->
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('../mobile/sw.js');
            });
        }
    </script>
</body>
</html>