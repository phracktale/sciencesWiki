import 'package:flutter/material.dart';

import '../api_client.dart';
import '../models.dart';
import 'node_screen.dart';

/// Accueil : les grands domaines de l'arbre des connaissances.
class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key, required this.api});

  final ApiClient api;

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {
  late Future<List<TreeNodeSummary>> _domains;

  @override
  void initState() {
    super.initState();
    _domains = widget.api.domains();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('SciencesWiki')),
      body: FutureBuilder<List<TreeNodeSummary>>(
        future: _domains,
        builder: (context, snapshot) {
          if (snapshot.connectionState != ConnectionState.done) {
            return const Center(child: CircularProgressIndicator());
          }
          if (snapshot.hasError) {
            return Center(child: Text('Erreur : ${snapshot.error}'));
          }
          final domains = snapshot.data ?? const [];
          return ListView(
            padding: const EdgeInsets.all(12),
            children: [
              const Padding(
                padding: EdgeInsets.symmetric(vertical: 8),
                child: Text(
                  'Le savoir scientifique, libre et vulgarisé à partir de publications en accès ouvert.',
                ),
              ),
              for (final domain in domains)
                Card(
                  child: ListTile(
                    title: Text(domain.label),
                    subtitle: Text('${domain.childrenCount} champ(s)'),
                    trailing: const Icon(Icons.chevron_right),
                    onTap: () => Navigator.of(context).push(
                      MaterialPageRoute(
                        builder: (_) => NodeScreen(api: widget.api, slug: domain.slug),
                      ),
                    ),
                  ),
                ),
            ],
          );
        },
      ),
    );
  }
}
