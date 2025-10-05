// GENERATED CODE - DO NOT MODIFY BY HAND

part of 'user_model.dart';

// **************************************************************************
// JsonSerializableGenerator
// **************************************************************************

UserModel _$UserModelFromJson(Map<String, dynamic> json) => UserModel(
      id: (json['id'] as num).toInt(),
      username: json['username'] as String?,
      email: json['email'] as String,
      fullName: json['full_name'] as String,
      phone: json['phone'] as String?,
      role: json['role'] as String,
      preferredLanguage: json['preferred_language'] as String?,
      phoneVerified: json['phone_verified'] as bool?,
      defaultDeliveryAddressId:
          (json['default_delivery_address_id'] as num?)?.toInt(),
      defaultAddressName: json['default_address_name'] as String?,
      defaultCity: json['default_city'] as String?,
      createdAt: json['created_at'] as String?,
    );

Map<String, dynamic> _$UserModelToJson(UserModel instance) => <String, dynamic>{
      'id': instance.id,
      'username': instance.username,
      'email': instance.email,
      'full_name': instance.fullName,
      'phone': instance.phone,
      'role': instance.role,
      'preferred_language': instance.preferredLanguage,
      'phone_verified': instance.phoneVerified,
      'default_delivery_address_id': instance.defaultDeliveryAddressId,
      'default_address_name': instance.defaultAddressName,
      'default_city': instance.defaultCity,
      'created_at': instance.createdAt,
    };
