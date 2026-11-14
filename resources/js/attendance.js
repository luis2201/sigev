for (const form of document.querySelectorAll('[data-attendance-review]')) {
    const decision = form.querySelector('[data-review-decision]');
    const fields = form.querySelector('[data-exception-fields]');
    const update = () => {
        const exceptional = decision.value === 'exceptional';
        fields.hidden = !exceptional;
        for (const input of fields.querySelectorAll('input, textarea')) {
            input.disabled = !exceptional;
            input.required = exceptional;
        }
        form.querySelector('[name="justification"]').required = decision.value !== '' && decision.value !== 'ordinary';
    };
    decision.addEventListener('change', update);
    update();
}
