import 'package:flutter_test/flutter_test.dart';
import 'package:scienceswiki/models.dart';

void main() {
  test('Answer.fromJson lit une Q/R publique', () {
    final answer = Answer.fromJson({
      'questionText': 'Qu\'est-ce que l\'ADN ?',
      'validationStatus': 'valide',
      'vulgarizationContent': 'Explication...',
      'academicContent': 'Faits...',
      'signature': 'Pr. Curie',
      'sources': [
        {'marker': 1, 'title': 'Un article', 'doi': '10.1/x'}
      ],
    });

    expect(answer.isValidated, isTrue);
    expect(answer.sources.single.doi, '10.1/x');
  });
}
