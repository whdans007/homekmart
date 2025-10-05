// GENERATED CODE - DO NOT MODIFY BY HAND

part of 'address_model.dart';

// **************************************************************************
// JsonSerializableGenerator
// **************************************************************************

AddressModel _$AddressModelFromJson(Map<String, dynamic> json) => AddressModel(
      id: (json['id'] as num).toInt(),
      userId: (json['user_id'] as num).toInt(),
      addressName: json['address_name'] as String,
      houseNumber: json['house_number'] as String?,
      street: json['street'] as String?,
      barangay: json['barangay'] as String?,
      city: json['city'] as String,
      province: json['province'] as String,
      postalCode: json['postal_code'] as String?,
      detailedAddress: json['detailed_address'] as String?,
      landmark: json['landmark'] as String?,
      deliveryNotes: json['delivery_notes'] as String?,
      latitude: (json['latitude'] as num?)?.toDouble(),
      longitude: (json['longitude'] as num?)?.toDouble(),
      isDefault: json['is_default'] as bool? ?? false,
      isActive: json['is_active'] as bool? ?? true,
      createdAt: json['created_at'] as String?,
      updatedAt: json['updated_at'] as String?,
    );

Map<String, dynamic> _$AddressModelToJson(AddressModel instance) =>
    <String, dynamic>{
      'id': instance.id,
      'user_id': instance.userId,
      'address_name': instance.addressName,
      'house_number': instance.houseNumber,
      'street': instance.street,
      'barangay': instance.barangay,
      'city': instance.city,
      'province': instance.province,
      'postal_code': instance.postalCode,
      'detailed_address': instance.detailedAddress,
      'landmark': instance.landmark,
      'delivery_notes': instance.deliveryNotes,
      'latitude': instance.latitude,
      'longitude': instance.longitude,
      'is_default': instance.isDefault,
      'is_active': instance.isActive,
      'created_at': instance.createdAt,
      'updated_at': instance.updatedAt,
    };
