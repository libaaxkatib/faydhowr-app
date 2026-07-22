/// Corner radius scale — docs/01_Brand_Design_Guide.md §10,
/// docs/08_Figma_Design_System.md §15.
abstract final class AppRadius {
  static const double sm = 8;

  /// Default radius for buttons, cards, text fields, list tiles.
  static const double md = 12;

  /// Media cards, hero containers, sheet top corners.
  static const double lg = 16;

  /// Rare marketing containers only.
  static const double xl = 24;

  /// Avatars and circular icon buttons only.
  static const double full = 999;
}
