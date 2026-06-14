import 'dart:convert';

import 'package:http/http.dart' as http;

import 'models.dart';

/// Client de l'API SciencesWiki. L'app ne touche jamais la base : elle consomme
/// l'API publique (cf. spec §5).
///
/// `baseUrl` par défaut : 10.0.2.2 = hôte de l'émulateur Android. Sur iOS
/// simulateur ou web, utiliser http://127.0.0.1:8000 ; en prod, le domaine de l'API.
class ApiClient {
  ApiClient({this.baseUrl = const String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'http://10.0.2.2:8000',
  )});

  final String baseUrl;

  Future<List<TreeNodeSummary>> domains() async {
    final data = await _getList('/api/tree_nodes', {
      'level': '0',
      'order[label]': 'asc',
    });
    return data
        .map((e) => TreeNodeSummary.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  Future<NodeDetail> node(String slug) async {
    final data = await _getObject('/api/tree_nodes/$slug');
    return NodeDetail.fromJson(data);
  }

  Future<List<Answer>> answers(String slug) async {
    final data = await _getList('/api/answers', {'treeNode.slug': slug});
    return data.map((e) => Answer.fromJson(e as Map<String, dynamic>)).toList();
  }

  Future<List<Map<String, dynamic>>> searchPublications(String query) async {
    final uri = _uri('/api/search', {'q': query, 'type': 'publications'});
    final response = await http.get(uri);
    _ensureOk(response);
    final decoded = jsonDecode(response.body) as Map<String, dynamic>;
    return (decoded['results'] as List<dynamic>? ?? [])
        .cast<Map<String, dynamic>>();
  }

  Future<List<dynamic>> _getList(String path, Map<String, String> query) async {
    final response = await http.get(_uri(path, query), headers: _headers);
    _ensureOk(response);
    return jsonDecode(response.body) as List<dynamic>;
  }

  Future<Map<String, dynamic>> _getObject(String path) async {
    final response = await http.get(_uri(path, const {}), headers: _headers);
    _ensureOk(response);
    return jsonDecode(response.body) as Map<String, dynamic>;
  }

  Uri _uri(String path, Map<String, String> query) =>
      Uri.parse('$baseUrl$path').replace(
        queryParameters: query.isEmpty ? null : query,
      );

  Map<String, String> get _headers => const {'Accept': 'application/json'};

  void _ensureOk(http.Response response) {
    if (response.statusCode < 200 || response.statusCode >= 300) {
      throw http.ClientException(
        'HTTP ${response.statusCode} sur ${response.request?.url}',
      );
    }
  }
}
