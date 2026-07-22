import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../../../core/theme/app_colors.dart';
import '../../../../core/theme/app_radius.dart';
import '../../../../core/theme/app_spacing.dart';
import '../../../../core/theme/app_typography.dart';
import '../../domain/entities/home_preview_entities.dart';
import 'home_section_header.dart';

/// Contact Card. Every row launches the matching external app: Phone opens
/// the dialer, WhatsApp opens the official app via wa.me, Email opens the
/// default mail client, Location opens Google/device Maps. Values are still
/// placeholder content — only the launch mechanism is real.
class ContactCardSection extends StatelessWidget {
  const ContactCardSection({required this.contactInfo, super.key});

  final ContactInfoPreview contactInfo;

  Future<void> _openPhone() => _launch(Uri(scheme: 'tel', path: _digitsAndPlus(contactInfo.phone)));

  Future<void> _openWhatsApp() =>
      _launch(Uri.parse('https://wa.me/${_digitsOnly(contactInfo.whatsapp)}'));

  Future<void> _openEmail() => _launch(Uri(scheme: 'mailto', path: contactInfo.email));

  Future<void> _openLocation() => _launch(
    Uri.parse('https://www.google.com/maps/search/?api=1&query=${Uri.encodeComponent(contactInfo.location)}'),
  );

  static Future<void> _launch(Uri uri) => launchUrl(uri, mode: LaunchMode.externalApplication);

  static String _digitsAndPlus(String value) => value.replaceAll(RegExp(r'[^\d+]'), '');

  static String _digitsOnly(String value) => value.replaceAll(RegExp(r'[^\d]'), '');

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        const HomeSectionHeader(title: 'Contact Fayadhowr'),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: AppSpacing.space3),
          child: Container(
            padding: const EdgeInsets.all(AppSpacing.space3),
            decoration: BoxDecoration(
              color: AppColors.white,
              borderRadius: BorderRadius.circular(AppRadius.lg),
              boxShadow: <BoxShadow>[
                BoxShadow(
                  color: AppColors.primary.withValues(alpha: 0.10),
                  blurRadius: 18,
                  offset: const Offset(0, 6),
                ),
              ],
            ),
            child: Column(
              children: <Widget>[
                _ContactRow(
                  icon: Icons.phone_outlined,
                  label: 'Phone',
                  value: contactInfo.phone,
                  accent: AppColors.primary,
                  onTap: _openPhone,
                ),
                const _ContactDivider(),
                _ContactRow(
                  icon: Icons.chat_bubble_outline,
                  label: 'WhatsApp',
                  value: contactInfo.whatsapp,
                  accent: AppColors.secondary,
                  onTap: _openWhatsApp,
                ),
                const _ContactDivider(),
                _ContactRow(
                  icon: Icons.email_outlined,
                  label: 'Email',
                  value: contactInfo.email,
                  accent: AppColors.primary,
                  onTap: _openEmail,
                ),
                const _ContactDivider(),
                _ContactRow(
                  icon: Icons.location_on_outlined,
                  label: 'Location',
                  value: contactInfo.location,
                  accent: AppColors.secondary,
                  onTap: _openLocation,
                ),
              ],
            ),
          ),
        ),
      ],
    );
  }
}

class _ContactDivider extends StatelessWidget {
  const _ContactDivider();

  @override
  Widget build(BuildContext context) => const Divider(height: AppSpacing.space5, color: AppColors.border);
}

class _ContactRow extends StatelessWidget {
  const _ContactRow({
    required this.icon,
    required this.label,
    required this.value,
    required this.accent,
    required this.onTap,
  });

  final IconData icon;
  final String label;
  final String value;
  final Color accent;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      button: true,
      label: '$label: $value',
      child: Material(
        color: Colors.transparent,
        child: InkWell(
          borderRadius: BorderRadius.circular(AppRadius.md),
          onTap: onTap,
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.center,
            children: <Widget>[
              Container(
                width: 40,
                height: 40,
                decoration: BoxDecoration(
                  color: accent.withValues(alpha: 0.10),
                  shape: BoxShape.circle,
                ),
                alignment: Alignment.center,
                child: Icon(icon, color: accent, size: 20),
              ),
              const SizedBox(width: AppSpacing.space3),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: <Widget>[
                    Text(label, style: AppTypography.caption),
                    Text(value, style: AppTypography.bodySmall),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
