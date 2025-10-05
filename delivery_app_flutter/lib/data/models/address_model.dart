import 'package:json_annotation/json_annotation.dart';

part 'address_model.g.dart';

/// 배송 주소 모델 (필리핀 주소 체계)
@JsonSerializable()
class AddressModel {
  final int id;
  @JsonKey(name: 'user_id')
  final int userId;
  @JsonKey(name: 'address_name')
  final String addressName;
  @JsonKey(name: 'house_number')
  final String? houseNumber;
  final String? street;
  final String? barangay; // 필리핀 행정구역
  final String city;
  final String province;
  @JsonKey(name: 'postal_code')
  final String? postalCode;
  @JsonKey(name: 'detailed_address')
  final String? detailedAddress;
  final String? landmark; // 랜드마크 (필리핀에서 중요)
  @JsonKey(name: 'delivery_notes')
  final String? deliveryNotes;
  final double? latitude;
  final double? longitude;
  @JsonKey(name: 'is_default')
  final bool isDefault;
  @JsonKey(name: 'is_active')
  final bool isActive;
  @JsonKey(name: 'created_at')
  final String? createdAt;
  @JsonKey(name: 'updated_at')
  final String? updatedAt;

  AddressModel({
    required this.id,
    required this.userId,
    required this.addressName,
    this.houseNumber,
    this.street,
    this.barangay,
    required this.city,
    required this.province,
    this.postalCode,
    this.detailedAddress,
    this.landmark,
    this.deliveryNotes,
    this.latitude,
    this.longitude,
    this.isDefault = false,
    this.isActive = true,
    this.createdAt,
    this.updatedAt,
  });

  factory AddressModel.fromJson(Map<String, dynamic> json) =>
      _$AddressModelFromJson(json);

  Map<String, dynamic> toJson() => _$AddressModelToJson(this);

  /// 전체 주소 포맷팅
  String get fullAddress {
    final parts = <String>[];

    if (houseNumber != null && houseNumber!.isNotEmpty) {
      parts.add(houseNumber!);
    }
    if (street != null && street!.isNotEmpty) {
      parts.add(street!);
    }
    if (barangay != null && barangay!.isNotEmpty) {
      parts.add('Brgy. $barangay');
    }
    parts.add(city);
    parts.add(province);
    if (postalCode != null && postalCode!.isNotEmpty) {
      parts.add(postalCode!);
    }

    return parts.join(', ');
  }

  /// 짧은 주소 (이름 + 도시)
  String get shortAddress => '$addressName - $city';

  /// 랜드마크 포함 주소
  String get addressWithLandmark {
    if (landmark != null && landmark!.isNotEmpty) {
      return '$fullAddress\nNear: $landmark';
    }
    return fullAddress;
  }

  /// GPS 좌표 있음 여부
  bool get hasCoordinates => latitude != null && longitude != null;

  /// Google Maps URL
  String? get googleMapsUrl {
    if (!hasCoordinates) return null;
    return 'https://www.google.com/maps?q=$latitude,$longitude';
  }

  /// 복사 메서드
  AddressModel copyWith({
    int? id,
    int? userId,
    String? addressName,
    String? houseNumber,
    String? street,
    String? barangay,
    String? city,
    String? province,
    String? postalCode,
    String? detailedAddress,
    String? landmark,
    String? deliveryNotes,
    double? latitude,
    double? longitude,
    bool? isDefault,
    bool? isActive,
    String? createdAt,
    String? updatedAt,
  }) {
    return AddressModel(
      id: id ?? this.id,
      userId: userId ?? this.userId,
      addressName: addressName ?? this.addressName,
      houseNumber: houseNumber ?? this.houseNumber,
      street: street ?? this.street,
      barangay: barangay ?? this.barangay,
      city: city ?? this.city,
      province: province ?? this.province,
      postalCode: postalCode ?? this.postalCode,
      detailedAddress: detailedAddress ?? this.detailedAddress,
      landmark: landmark ?? this.landmark,
      deliveryNotes: deliveryNotes ?? this.deliveryNotes,
      latitude: latitude ?? this.latitude,
      longitude: longitude ?? this.longitude,
      isDefault: isDefault ?? this.isDefault,
      isActive: isActive ?? this.isActive,
      createdAt: createdAt ?? this.createdAt,
      updatedAt: updatedAt ?? this.updatedAt,
    );
  }
}
