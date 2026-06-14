/// Modèles de données du client mobile (miroir des ressources de l'API).

class NodeRef {
  const NodeRef({required this.slug, required this.label, this.level = 0});

  final String slug;
  final String label;
  final int level;

  factory NodeRef.fromJson(Map<String, dynamic> json) => NodeRef(
        slug: json['slug'] as String,
        label: json['label'] as String,
        level: (json['level'] as int?) ?? 0,
      );
}

/// Élément de l'arbre (collection /api/tree_nodes).
class TreeNodeSummary {
  const TreeNodeSummary({
    required this.slug,
    required this.label,
    required this.level,
    required this.childrenCount,
  });

  final String slug;
  final String label;
  final int level;
  final int childrenCount;

  factory TreeNodeSummary.fromJson(Map<String, dynamic> json) => TreeNodeSummary(
        slug: json['slug'] as String,
        label: json['label'] as String,
        level: (json['level'] as int?) ?? 0,
        childrenCount: (json['childrenCount'] as int?) ?? 0,
      );
}

/// Détail d'un nœud (/api/tree_nodes/{slug}).
class NodeDetail {
  const NodeDetail({
    required this.slug,
    required this.label,
    this.description,
    this.children = const [],
    this.parents = const [],
  });

  final String slug;
  final String label;
  final String? description;
  final List<NodeRef> children;
  final List<NodeRef> parents;

  factory NodeDetail.fromJson(Map<String, dynamic> json) => NodeDetail(
        slug: json['slug'] as String,
        label: json['label'] as String,
        description: json['description'] as String?,
        children: _refs(json['children']),
        parents: _refs(json['parents']),
      );

  static List<NodeRef> _refs(dynamic raw) => (raw as List<dynamic>? ?? [])
      .map((e) => NodeRef.fromJson(e as Map<String, dynamic>))
      .toList();
}

/// Source (note de bas de page) d'une réponse.
class AnswerSource {
  const AnswerSource({required this.marker, required this.title, this.doi});

  final int marker;
  final String title;
  final String? doi;

  factory AnswerSource.fromJson(Map<String, dynamic> json) => AnswerSource(
        marker: (json['marker'] as int?) ?? 0,
        title: json['title'] as String? ?? '',
        doi: json['doi'] as String?,
      );
}

/// Q/R publique (/api/answers).
class Answer {
  const Answer({
    required this.questionText,
    required this.validationStatus,
    required this.vulgarizationContent,
    required this.academicContent,
    required this.signature,
    this.sources = const [],
  });

  final String questionText;
  final String validationStatus;
  final String vulgarizationContent;
  final String academicContent;
  final String signature;
  final List<AnswerSource> sources;

  bool get isValidated => validationStatus == 'valide';

  factory Answer.fromJson(Map<String, dynamic> json) => Answer(
        questionText: json['questionText'] as String? ?? '',
        validationStatus: json['validationStatus'] as String? ?? 'non_relu',
        vulgarizationContent: json['vulgarizationContent'] as String? ?? '',
        academicContent: json['academicContent'] as String? ?? '',
        signature: json['signature'] as String? ?? '',
        sources: (json['sources'] as List<dynamic>? ?? [])
            .map((e) => AnswerSource.fromJson(e as Map<String, dynamic>))
            .toList(),
      );
}
