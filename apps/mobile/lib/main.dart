import 'package:flutter/material.dart';

import 'api_client.dart';
import 'screens/home_screen.dart';

void main() => runApp(const SciencesWikiApp());

class SciencesWikiApp extends StatelessWidget {
  const SciencesWikiApp({super.key});

  @override
  Widget build(BuildContext context) {
    final api = ApiClient();
    return MaterialApp(
      title: 'SciencesWiki',
      debugShowCheckedModeBanner: false,
      theme: ThemeData(
        colorSchemeSeed: const Color(0xFF11AA55),
        useMaterial3: true,
      ),
      home: HomeScreen(api: api),
    );
  }
}
