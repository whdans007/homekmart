import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:go_router/go_router.dart';
import '../../../core/config/theme_config.dart';
import '../../providers/auth_provider.dart';
import '../../widgets/loading_overlay.dart';
import '../../routes/app_router.dart';

/// 프로필 화면
class ProfileScreen extends StatefulWidget {
  const ProfileScreen({super.key});

  @override
  State<ProfileScreen> createState() => _ProfileScreenState();
}

class _ProfileScreenState extends State<ProfileScreen> {
  Future<void> _handleLogout() async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Logout'),
        content: const Text('Are you sure you want to logout?'),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Cancel'),
          ),
          TextButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Logout'),
          ),
        ],
      ),
    );

    if (confirmed == true && mounted) {
      final authProvider = context.read<AuthProvider>();
      await authProvider.logout();

      if (!mounted) return;
      context.go(AppRouter.login);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Profile'),
      ),
      body: Consumer<AuthProvider>(
        builder: (context, authProvider, child) {
          final user = authProvider.user;

          return LoadingOverlay(
            isLoading: authProvider.isLoading,
            child: ListView(
              children: [
                // 사용자 정보 헤더
                Container(
                  color: ThemeConfig.primaryColor,
                  padding: const EdgeInsets.all(ThemeConfig.spacingXl),
                  child: Column(
                    children: [
                      CircleAvatar(
                        radius: 50,
                        backgroundColor: Colors.white,
                        child: Text(
                          user?.name.substring(0, 1).toUpperCase() ?? 'U',
                          style: const TextStyle(
                            fontSize: 40,
                            fontWeight: FontWeight.bold,
                            color: ThemeConfig.primaryColor,
                          ),
                        ),
                      ),
                      const SizedBox(height: ThemeConfig.spacingMd),
                      Text(
                        user?.name ?? 'User',
                        style: Theme.of(context).textTheme.titleLarge?.copyWith(
                              color: Colors.white,
                              fontWeight: FontWeight.bold,
                            ),
                      ),
                      const SizedBox(height: ThemeConfig.spacingXs),
                      Text(
                        user?.email ?? '',
                        style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                              color: Colors.white.withOpacity(0.9),
                            ),
                      ),
                    ],
                  ),
                ),

                // 계정 섹션
                _buildSection(
                  title: 'Account',
                  items: [
                    _buildMenuItem(
                      icon: Icons.person_outline,
                      title: 'Edit Profile',
                      onTap: () {
                        // TODO: Edit profile screen
                      },
                    ),
                    _buildMenuItem(
                      icon: Icons.location_on_outlined,
                      title: 'My Addresses',
                      onTap: () {
                        // TODO: Addresses screen
                      },
                    ),
                    _buildMenuItem(
                      icon: Icons.lock_outline,
                      title: 'Change Password',
                      onTap: () {
                        // TODO: Change password screen
                      },
                    ),
                  ],
                ),

                // 주문 섹션
                _buildSection(
                  title: 'Orders',
                  items: [
                    _buildMenuItem(
                      icon: Icons.receipt_long_outlined,
                      title: 'Order History',
                      onTap: () => context.push(AppRouter.orders),
                    ),
                  ],
                ),

                // 설정 섹션
                _buildSection(
                  title: 'Settings',
                  items: [
                    _buildMenuItem(
                      icon: Icons.notifications_outlined,
                      title: 'Notifications',
                      trailing: Switch(
                        value: true,
                        onChanged: (value) {
                          // TODO: Toggle notifications
                        },
                      ),
                    ),
                    _buildMenuItem(
                      icon: Icons.language_outlined,
                      title: 'Language',
                      subtitle: 'English',
                      onTap: () {
                        // TODO: Language selection
                      },
                    ),
                  ],
                ),

                // 지원 섹션
                _buildSection(
                  title: 'Support',
                  items: [
                    _buildMenuItem(
                      icon: Icons.help_outline,
                      title: 'Help Center',
                      onTap: () {
                        // TODO: Help center
                      },
                    ),
                    _buildMenuItem(
                      icon: Icons.privacy_tip_outlined,
                      title: 'Privacy Policy',
                      onTap: () {
                        // TODO: Privacy policy
                      },
                    ),
                    _buildMenuItem(
                      icon: Icons.description_outlined,
                      title: 'Terms of Service',
                      onTap: () {
                        // TODO: Terms of service
                      },
                    ),
                  ],
                ),

                // 로그아웃
                Padding(
                  padding: const EdgeInsets.all(ThemeConfig.spacingMd),
                  child: ListTile(
                    leading: const Icon(
                      Icons.logout,
                      color: ThemeConfig.errorColor,
                    ),
                    title: const Text(
                      'Logout',
                      style: TextStyle(
                        color: ThemeConfig.errorColor,
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                    onTap: _handleLogout,
                  ),
                ),

                // 앱 버전
                Padding(
                  padding: const EdgeInsets.all(ThemeConfig.spacingMd),
                  child: Text(
                    'Version 1.0.0',
                    textAlign: TextAlign.center,
                    style: Theme.of(context).textTheme.bodySmall?.copyWith(
                          color: ThemeConfig.textSecondary,
                        ),
                  ),
                ),
              ],
            ),
          );
        },
      ),
    );
  }

  Widget _buildSection({
    required String title,
    required List<Widget> items,
  }) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(
            ThemeConfig.spacingMd,
            ThemeConfig.spacingXl,
            ThemeConfig.spacingMd,
            ThemeConfig.spacingSm,
          ),
          child: Text(
            title,
            style: Theme.of(context).textTheme.titleSmall?.copyWith(
                  color: ThemeConfig.textSecondary,
                  fontWeight: FontWeight.bold,
                ),
          ),
        ),
        ...items,
      ],
    );
  }

  Widget _buildMenuItem({
    required IconData icon,
    required String title,
    String? subtitle,
    Widget? trailing,
    VoidCallback? onTap,
  }) {
    return ListTile(
      leading: Icon(icon),
      title: Text(title),
      subtitle: subtitle != null ? Text(subtitle) : null,
      trailing: trailing ?? const Icon(Icons.chevron_right),
      onTap: onTap,
    );
  }
}
