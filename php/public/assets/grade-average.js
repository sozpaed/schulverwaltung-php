const gradeFields = ['deutsch', 'mathematik', 'sachkunde']
    .map((id) => document.getElementById(id))
    .filter((field) => field instanceof HTMLInputElement);
const averageOutput = document.getElementById('durchschnitt');

if (gradeFields.length === 3 && averageOutput instanceof HTMLOutputElement) {
    const updateAverage = () => {
        const grades = gradeFields
            .map((field) => field.value)
            .filter((value) => /^[1-6]$/.test(value))
            .map(Number);

        averageOutput.value = grades.length === 0
            ? '–'
            : (grades.reduce((sum, grade) => sum + grade, 0) / grades.length)
                .toFixed(2)
                .replace('.', ',');
    };

    gradeFields.forEach((field) => field.addEventListener('input', updateAverage));
}
