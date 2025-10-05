import 'package:json_annotation/json_annotation.dart';

part 'user_model.g.dart';

/// 사용자 모델
@JsonSerializable()
class UserModel {
  final int id;
  final String? username;
  final String email;
  @JsonKey(name: 'full_name')
  final String fullName;
  final String? phone;
  final String role;
  @JsonKey(name: 'preferred_language')
  final String? preferredLanguage;
  @JsonKey(name: 'phone_verified')
  final bool? phoneVerified;
  @JsonKey(name: 'default_delivery_address_id')
  final int? defaultDeliveryAddressId;
  @JsonKey(name: 'default_address_name')
  final String? defaultAddressName;
  @JsonKey(name: 'default_city')
  final String? defaultCity;
  @JsonKey(name: 'created_at')
  final String? createdAt;

  UserModel({
    required this.id,
    this.username,
    required this.email,
    required this.fullName,
    this.phone,
    required this.role,
    this.preferredLanguage,
    this.phoneVerified,
    this.defaultDeliveryAddressId,
    this.defaultAddressName,
    this.defaultCity,
    this.createdAt,
  });

  factory UserModel.fromJson(Map<String, dynamic> json) =>
      _$UserModelFromJson(json);

  Map<String, dynamic> toJson() => _$UserModelToJson(this);

  /// Alias for compatibility
  String get name => fullName;

  /// 표시 이름 가져오기
  String get displayName => fullName.isNotEmpty ? fullName : (username ?? email);

  /// 이니셜 가져오기 (프로필 아바타용)
  String get initials {
    final names = fullName.split(' ');
    if (names.length >= 2) {
      return '${names[0][0]}${names[1][0]}'.toUpperCase();
    }
    return fullName.isNotEmpty ? fullName[0].toUpperCase() : email[0].toUpperCase();
  }
}
