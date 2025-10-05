import 'package:flutter/material.dart';
import '../../core/config/theme_config.dart';

/// 재사용 가능한 버튼 컴포넌트
class AppButton extends StatelessWidget {
  final String text;
  final VoidCallback? onPressed;
  final bool isLoading;
  final AppButtonType type;
  final IconData? icon;
  final double? width;
  final double height;

  const AppButton({
    super.key,
    required this.text,
    this.onPressed,
    this.isLoading = false,
    this.type = AppButtonType.primary,
    this.icon,
    this.width,
    this.height = 50,
  });

  @override
  Widget build(BuildContext context) {
    final Widget child = isLoading
        ? SizedBox(
            width: 20,
            height: 20,
            child: CircularProgressIndicator(
              strokeWidth: 2,
              valueColor: AlwaysStoppedAnimation<Color>(
                type == AppButtonType.primary ? Colors.white : ThemeConfig.primaryColor,
              ),
            ),
          )
        : icon != null
            ? Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Icon(icon, size: 20),
                  const SizedBox(width: ThemeConfig.spacingSm),
                  Text(text),
                ],
              )
            : Text(text);

    final buttonStyle = _getButtonStyle(type);

    return SizedBox(
      width: width,
      height: height,
      child: type == AppButtonType.text
          ? TextButton(
              onPressed: isLoading ? null : onPressed,
              style: buttonStyle,
              child: child,
            )
          : type == AppButtonType.outlined
              ? OutlinedButton(
                  onPressed: isLoading ? null : onPressed,
                  style: buttonStyle,
                  child: child,
                )
              : ElevatedButton(
                  onPressed: isLoading ? null : onPressed,
                  style: buttonStyle,
                  child: child,
                ),
    );
  }

  ButtonStyle? _getButtonStyle(AppButtonType type) {
    switch (type) {
      case AppButtonType.primary:
        return ElevatedButton.styleFrom(
          backgroundColor: ThemeConfig.primaryColor,
          foregroundColor: Colors.white,
          elevation: 0,
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(ThemeConfig.radiusMd),
          ),
        );
      case AppButtonType.secondary:
        return ElevatedButton.styleFrom(
          backgroundColor: ThemeConfig.surfaceColor,
          foregroundColor: ThemeConfig.textPrimary,
          elevation: 0,
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(ThemeConfig.radiusMd),
          ),
        );
      case AppButtonType.outlined:
        return OutlinedButton.styleFrom(
          foregroundColor: ThemeConfig.primaryColor,
          side: const BorderSide(color: ThemeConfig.primaryColor),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(ThemeConfig.radiusMd),
          ),
        );
      case AppButtonType.text:
        return TextButton.styleFrom(
          foregroundColor: ThemeConfig.primaryColor,
        );
    }
  }
}

/// 버튼 타입
enum AppButtonType {
  primary,
  secondary,
  outlined,
  text,
}
