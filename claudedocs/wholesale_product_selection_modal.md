# 도매상품 선택 모달 기능 추가

## 구현 내용

도매상품으로 등록된 상품을 바코드나 검색으로 추가할 때, 사용자가 **도매 등록가격**과 **인벤토리 원가 기반 가격** 중 선택할 수 있는 모달 팝업을 추가했습니다.

## 수정 파일

### 1. wholesale_sales.php

#### HTML - 모달 추가 (560-607줄)
```html
<!-- 상품 타입 선택 모달 -->
<div id="product-type-selection-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl">
            <!-- 모달 헤더 -->
            <div class="flex items-center justify-between p-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">
                    <i class="fas fa-box-open mr-2 text-blue-500"></i>
                    상품 선택
                </h3>
                <button type="button" id="close-type-selection-modal">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>

            <!-- 모달 본문 -->
            <div class="p-6">
                <p class="text-sm text-gray-600 mb-4">
                    이 상품은 도매상품으로 등록되어 있습니다. 어떤 가격으로 판매하시겠습니까?
                </p>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <!-- 도매상품 옵션 -->
                    <div id="wholesale-option" class="border-2 border-blue-500 rounded-lg p-4 cursor-pointer hover:bg-blue-50">
                        <div class="flex items-center justify-between mb-2">
                            <h4 class="font-medium text-gray-900">도매 등록상품</h4>
                            <span class="px-2 py-1 bg-blue-100 text-blue-800 text-xs rounded">추천</span>
                        </div>
                        <div class="text-sm text-gray-600 space-y-1">
                            <div>상품명: <span id="modal-wholesale-name"></span></div>
                            <div>도매가: <span id="modal-wholesale-price"></span>원</div>
                            <div class="text-xs text-gray-500 mt-2">등록된 도매가격으로 판매합니다</div>
                        </div>
                    </div>

                    <!-- 인벤토리 상품 옵션 -->
                    <div id="inventory-option" class="border-2 border-gray-300 rounded-lg p-4 cursor-pointer hover:bg-gray-50">
                        <div class="flex items-center justify-between mb-2">
                            <h4 class="font-medium text-gray-900">인벤토리 상품</h4>
                            <span class="px-2 py-1 bg-gray-100 text-gray-600 text-xs rounded">일반</span>
                        </div>
                        <div class="text-sm text-gray-600 space-y-1">
                            <div>상품명: <span id="modal-inventory-name"></span></div>
                            <div>원가: <span id="modal-inventory-cost"></span>원</div>
                            <div>판매가: <span id="modal-inventory-price"></span>원</div>
                            <div class="text-xs text-gray-500 mt-2">원가에 마진율을 적용하여 판매합니다</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
```

#### JavaScript - 함수 수정 (1232-1330줄)

**1. addProductToCartDirect() 수정**
```javascript
function addProductToCartDirect(product) {
    // 도매상품으로 등록되어 있고 원가 정보도 있는 경우 모달 표시
    if (product.is_registered && product.cost_price && parseFloat(product.cost_price) > 0) {
        showProductTypeSelectionModal(product);
        return;
    }

    // 그 외의 경우 바로 장바구니에 추가
    addToCart(product, product.wholesale_price);
}
```

**2. addToCart() 함수 추가**
```javascript
function addToCart(product, price) {
    const existingIndex = cart.findIndex(item => item.product_id == product.product_id);

    if (existingIndex >= 0) {
        cart[existingIndex].quantity += 1;
        cart[existingIndex].total_price = cart[existingIndex].quantity * cart[existingIndex].unit_price;
        showNotification('수량이 증가되었습니다.', 'success');
    } else {
        cart.push({
            product_id: product.product_id,
            sku: product.sku,
            name_ko: product.name_ko,
            name_en: product.name_en,
            unit_price: price,
            quantity: 1,
            total_price: price * 1,
            min_quantity: product.min_quantity,
            wholesale_price: price,
            wholesale_price_piece: product.wholesale_price_piece || 0,
            sale_unit: 'box',
            remarks: ''
        });

        if (!product.is_registered) {
            showNotification(`미등록 상품입니다. 원가에 ${product.margin_rate}% 마진이 적용되었습니다.`, 'info');
        } else {
            showNotification('상품이 장바구니에 추가되었습니다.', 'success');
        }
    }

    updateCart();
}
```

**3. showProductTypeSelectionModal() 함수 추가**
```javascript
function showProductTypeSelectionModal(product) {
    const modal = document.getElementById('product-type-selection-modal');
    const marginRateInput = document.getElementById('margin_rate');
    const marginRate = marginRateInput ? parseFloat(marginRateInput.value) : 15.0;

    // 원가 기반 판매가 계산
    const costPrice = parseFloat(product.cost_price);
    const inventoryPrice = Math.round(costPrice * (1 + marginRate / 100) * 100) / 100;

    // 모달 데이터 채우기
    document.getElementById('modal-wholesale-name').textContent = product.name_ko;
    document.getElementById('modal-wholesale-price').textContent = parseFloat(product.wholesale_price).toLocaleString();

    document.getElementById('modal-inventory-name').textContent = product.name_ko;
    document.getElementById('modal-inventory-cost').textContent = costPrice.toLocaleString();
    document.getElementById('modal-inventory-price').textContent = inventoryPrice.toLocaleString();

    // 모달 표시
    modal.classList.remove('hidden');

    // 이벤트 리스너 설정
    const wholesaleHandler = () => {
        modal.classList.add('hidden');
        addToCart(product, parseFloat(product.wholesale_price));
    };

    const inventoryHandler = () => {
        modal.classList.add('hidden');
        const modifiedProduct = {...product, wholesale_price: inventoryPrice, is_registered: false, margin_rate: marginRate};
        addToCart(modifiedProduct, inventoryPrice);
    };

    const closeHandler = () => {
        modal.classList.add('hidden');
    };

    // 기존 리스너 제거 후 새로 추가 (중복 방지)
    const wholesaleOption = document.getElementById('wholesale-option');
    const inventoryOption = document.getElementById('inventory-option');
    const closeBtn = document.getElementById('close-type-selection-modal');

    wholesaleOption.replaceWith(wholesaleOption.cloneNode(true));
    inventoryOption.replaceWith(inventoryOption.cloneNode(true));
    closeBtn.replaceWith(closeBtn.cloneNode(true));

    document.getElementById('wholesale-option').addEventListener('click', wholesaleHandler);
    document.getElementById('inventory-option').addEventListener('click', inventoryHandler);
    document.getElementById('close-type-selection-modal').addEventListener('click', closeHandler);
}
```

## 동작 방식

### 조건
모달 팝업은 다음 조건을 **모두** 만족할 때 표시됩니다:
1. `product.is_registered === true` - 도매상품으로 등록되어 있음
2. `product.cost_price` 존재 - 원가 정보가 있음
3. `parseFloat(product.cost_price) > 0` - 원가가 0보다 큼

### 선택 옵션

#### 1. 도매 등록상품 (추천)
- **가격**: 도매상품 테이블에 등록된 도매가 사용
- **표시**: 파란색 테두리, "추천" 배지
- **클릭 시**: 등록된 도매가로 장바구니에 추가

#### 2. 인벤토리 상품 (일반)
- **가격**: 원가 × (1 + 마진율/100)
- **표시**: 회색 테두리, "일반" 배지
- **원가**: inventory 테이블의 cost_price
- **판매가**: 실시간 계산된 가격 표시
- **클릭 시**: 계산된 가격으로 장바구니에 추가

### 모달이 표시되지 않는 경우

다음 경우에는 모달 없이 바로 장바구니에 추가됩니다:
- 도매상품으로 미등록 (`is_registered === false`)
- 원가 정보가 없음 (`cost_price === null`)
- 원가가 0 (`cost_price <= 0`)

이런 경우 미등록 상품으로 처리되며, 원가가 0이면 에러 메시지가 표시됩니다.

## 사용자 경험

1. 바코드 스캔 또는 상품명 검색
2. 도매상품이고 원가가 있으면 → 모달 팝업 표시
3. 사용자가 두 옵션 중 하나 선택
4. 선택한 가격으로 장바구니에 추가
5. 성공 알림 표시

## 기술적 특징

- **이벤트 리스너 중복 방지**: `replaceWith()` + `cloneNode(true)`로 기존 리스너 제거
- **클로저 활용**: 각 모달 호출마다 독립적인 product 데이터 유지
- **실시간 계산**: 현재 설정된 마진율을 즉시 반영
- **반응형 디자인**: 모바일에서는 1열, 데스크톱에서는 2열 그리드
- **접근성**: 명확한 아이콘, 배지, 설명 텍스트
