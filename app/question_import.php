<?php
declare(strict_types=1);

function readQuestionImport(): array
{
    $file = $_FILES['questions_xml'] ?? [];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
        || $file['size'] > 1024 * 1024 || !is_uploaded_file($file['tmp_name'])) {
        throw new DomainException('Завантажте XML-файл розміром до 1 МБ.');
    }
    $document = new DOMDocument();
    if (!@$document->load($file['tmp_name'], LIBXML_NONET) || $document->doctype
        || $document->documentElement?->tagName !== 'questions') {
        throw new DomainException('Файл має бути коректним XML із кореневим елементом <questions> і без DTD.');
    }
    $questions = [];
    foreach ($document->documentElement->childNodes as $node) {
        if (!$node instanceof DOMElement) {
            continue;
        }
        if ($node->tagName !== 'question' || count($questions) >= 200) {
            throw new DomainException('Додайте від 1 до 200 елементів <question> всередині <questions>.');
        }
        $fields = ['type' => $node->getAttribute('type'), 'max_points' => $node->hasAttribute('points') ? $node->getAttribute('points') : '1',
            'options' => [], 'correct' => []];
        try {
            foreach ($node->childNodes as $element) {
                if (!$element instanceof DOMElement) {
                    continue;
                }
                if ($element->childElementCount) {
                    throw new DomainException('У полях запитання та відповіді використовуйте звичайний текст.');
                }
                switch ($element->tagName) {
                    case 'prompt':
                    case 'grading_guidance':
                        if (isset($fields[$element->tagName])) {
                            throw new DomainException('Поле <' . $element->tagName . '> має бути в одному екземплярі.');
                        }
                        $fields[$element->tagName] = $element->textContent;
                        break;
                    case 'option':
                        $correct = $element->getAttribute('correct');
                        if ($fields['type'] === 'written' || !in_array($correct, ['0', '1'], true)
                            || trim($element->textContent) === '') {
                            throw new DomainException('Варіант має містити текст і correct="0" або correct="1". Для розгорнутої відповіді використовуйте <grading_guidance>.');
                        }
                        if ($correct === '1') {
                            $fields['correct'][] = (string) count($fields['options']);
                        }
                        $fields['options'][] = $element->textContent;
                        break;
                    default:
                        throw new DomainException('Невідомий елемент <' . $element->tagName . '>. Скористайтеся шаблоном.');
                }
            }
            $questions[] = questionData($fields);
        } catch (DomainException $exception) {
            throw new DomainException('Запитання ' . (count($questions) + 1) . '. ' . $exception->getMessage());
        }
    }
    if (!$questions) {
        throw new DomainException('Файл не містить запитань.');
    }
    return $questions;
}
