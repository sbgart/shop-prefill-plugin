#!/usr/bin/env bash

# A11 (TEST-PLAN.md §5): один вызов вместо `for t in tests/*Test.php; do php "$t"; done`
# для L0/L1 и CI — суммарный код возврата плюс итоговая строка «N файлов, M проверок,
# K провалов». Источник истины по провалу файла — код возврата (см. TESTS.md:
# «Ненулевой код возврата = провал»), а не текст вывода: стили финального echo
# в разных тестах плагина исторически разошлись (часть считает и печатает счётчик
# проверок, часть бросает исключение на первом же провале и просто печатает "Test: OK").
# Поэтому «M проверок» — это best-effort сумма по тем файлам, где счётчик удалось
# распознать, а не гарантированно точный тотал по всем файлам.
#
# Запуск: tests/run.sh   (или: bash tests/run.sh)

set -uo pipefail

cd "$(dirname "$0")"

files=0
failed_files=0
total_checks=0
failed_list=()

for t in *Test.php; do
    [[ -e "$t" ]] || continue
    files=$((files + 1))

    output="$(php "$t" 2>&1)"
    status=$?

    n=""
    if [[ "$output" =~ PASSED:\ ([0-9]+)\ checks ]]; then
        n="${BASH_REMATCH[1]}"
    elif [[ "$output" =~ FAILED:\ [0-9]+\ of\ ([0-9]+)\ checks ]]; then
        n="${BASH_REMATCH[1]}"
    elif [[ "$output" =~ ([0-9]+)\ проверок,\ [0-9]+\ провалено ]]; then
        n="${BASH_REMATCH[1]}"
    elif [[ "$output" =~ OK:\ ([0-9]+)\ проверок\ пройдено ]]; then
        n="${BASH_REMATCH[1]}"
    elif [[ "$output" =~ ПРОВАЛЕНО:\ [0-9]+\ из\ ([0-9]+) ]]; then
        n="${BASH_REMATCH[1]}"
    elif [[ "$output" =~ OK:\ ([0-9]+)\ checks\ passed ]]; then
        n="${BASH_REMATCH[1]}"
    elif [[ "$output" =~ FAILED:\ [0-9]+\ /\ ([0-9]+)\ checks ]]; then
        n="${BASH_REMATCH[1]}"
    fi

    if [[ -n "$n" ]]; then
        total_checks=$((total_checks + n))
    fi

    if [[ $status -ne 0 ]]; then
        failed_files=$((failed_files + 1))
        failed_list+=("$t")
        echo "ПРОВАЛ: $t"
        echo "$output" | sed 's/^/    /'
    fi
done

echo
echo "${files} файлов, ${total_checks} проверок, ${failed_files} провалов"

if [[ ${#failed_list[@]} -gt 0 ]]; then
    echo "Упавшие файлы: ${failed_list[*]}"
    exit 1
fi

exit 0
