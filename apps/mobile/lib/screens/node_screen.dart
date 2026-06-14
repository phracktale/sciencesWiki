import 'package:flutter/material.dart';

import '../api_client.dart';
import '../models.dart';

/// Page d'une notion : fil d'Ariane, sous-domaines, et Q/R publiques sourcées.
class NodeScreen extends StatefulWidget {
  const NodeScreen({super.key, required this.api, required this.slug});

  final ApiClient api;
  final String slug;

  @override
  State<NodeScreen> createState() => _NodeScreenState();
}

class _NodeScreenState extends State<NodeScreen> {
  late Future<(NodeDetail, List<Answer>)> _data;

  @override
  void initState() {
    super.initState();
    _data = _load();
  }

  Future<(NodeDetail, List<Answer>)> _load() async {
    final node = await widget.api.node(widget.slug);
    final answers = await widget.api.answers(widget.slug);
    return (node, answers);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('SciencesWiki')),
      body: FutureBuilder<(NodeDetail, List<Answer>)>(
        future: _data,
        builder: (context, snapshot) {
          if (snapshot.connectionState != ConnectionState.done) {
            return const Center(child: CircularProgressIndicator());
          }
          if (snapshot.hasError) {
            return Center(child: Text('Erreur : ${snapshot.error}'));
          }
          final (node, answers) = snapshot.data!;
          return ListView(
            padding: const EdgeInsets.all(16),
            children: [
              if (node.parents.isNotEmpty)
                Wrap(
                  spacing: 6,
                  children: [
                    for (final parent in node.parents)
                      ActionChip(
                        label: Text(parent.label),
                        onPressed: () => _open(parent.slug),
                      ),
                  ],
                ),
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 8),
                child: Text(node.label,
                    style: Theme.of(context).textTheme.headlineSmall),
              ),
              if (node.description != null) Text(node.description!),
              if (node.children.isNotEmpty) ...[
                const SizedBox(height: 16),
                Text('Sous-domaines',
                    style: Theme.of(context).textTheme.titleMedium),
                Wrap(
                  spacing: 6,
                  children: [
                    for (final child in node.children)
                      ActionChip(
                        label: Text(child.label),
                        onPressed: () => _open(child.slug),
                      ),
                  ],
                ),
              ],
              const SizedBox(height: 16),
              Text('Questions & réponses',
                  style: Theme.of(context).textTheme.titleMedium),
              if (answers.isEmpty)
                const Padding(
                  padding: EdgeInsets.symmetric(vertical: 8),
                  child: Text('Aucune question publiée pour cette notion.'),
                ),
              for (final answer in answers) _AnswerCard(answer: answer),
            ],
          );
        },
      ),
    );
  }

  void _open(String slug) => Navigator.of(context).push(
        MaterialPageRoute(builder: (_) => NodeScreen(api: widget.api, slug: slug)),
      );
}

class _AnswerCard extends StatelessWidget {
  const _AnswerCard({required this.answer});

  final Answer answer;

  @override
  Widget build(BuildContext context) {
    return Card(
      margin: const EdgeInsets.symmetric(vertical: 8),
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(answer.questionText,
                style: Theme.of(context).textTheme.titleMedium),
            const SizedBox(height: 6),
            _Badge(validated: answer.isValidated),
            if (answer.vulgarizationContent.isNotEmpty) ...[
              const SizedBox(height: 10),
              const Text('Vulgarisation',
                  style: TextStyle(fontWeight: FontWeight.bold)),
              Text(answer.vulgarizationContent),
            ],
            if (answer.academicContent.isNotEmpty) ...[
              const SizedBox(height: 10),
              const Text('Faits établis (sourcés)',
                  style: TextStyle(fontWeight: FontWeight.bold)),
              Text(answer.academicContent),
            ],
            if (answer.sources.isNotEmpty) ...[
              const SizedBox(height: 10),
              const Text('Sources', style: TextStyle(fontWeight: FontWeight.bold)),
              for (final source in answer.sources)
                Text(
                  '[${source.marker}] ${source.title}'
                  '${source.doi != null ? ' — doi:${source.doi}' : ''}',
                  style: Theme.of(context).textTheme.bodySmall,
                ),
            ],
            const SizedBox(height: 8),
            Text('Signé : ${answer.signature}',
                style: Theme.of(context).textTheme.bodySmall),
          ],
        ),
      ),
    );
  }
}

class _Badge extends StatelessWidget {
  const _Badge({required this.validated});

  final bool validated;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
      decoration: BoxDecoration(
        color: validated ? const Color(0xFFDCFCE7) : const Color(0xFFFEF3C7),
        borderRadius: BorderRadius.circular(999),
      ),
      child: Text(
        validated
            ? '✅ Validé par le comité scientifique'
            : '⚠️ Généré par IA — non encore relu',
        style: TextStyle(
          fontSize: 12,
          color: validated ? const Color(0xFF166534) : const Color(0xFF92400E),
        ),
      ),
    );
  }
}
