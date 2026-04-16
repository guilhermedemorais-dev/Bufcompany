(() => {
  const forms = document.querySelectorAll('[data-contact-form]');

  if (!forms.length) {
    return;
  }

  const setStatus = (node, message, tone) => {
    if (!node) {
      return;
    }

    node.textContent = message;
    node.classList.remove('is-success', 'is-error');

    if (tone === 'success') {
      node.classList.add('is-success');
    }

    if (tone === 'error') {
      node.classList.add('is-error');
    }
  };

  forms.forEach((form) => {
    const statusNode = form.querySelector('[data-form-status]');
    const submitButton = form.querySelector('button[type="submit"]');
    const defaultButtonHtml = submitButton ? submitButton.innerHTML : '';

    form.addEventListener('submit', async (event) => {
      event.preventDefault();

      const formData = new FormData(form);
      setStatus(statusNode, 'Sending your message...', null);

      if (submitButton) {
        submitButton.disabled = true;
        submitButton.setAttribute('aria-busy', 'true');
        submitButton.textContent = 'SENDING...';
      }

      try {
        const response = await fetch(form.action, {
          method: 'POST',
          body: formData,
          headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
          }
        });

        const payload = await response.json().catch(() => ({}));

        if (!response.ok || !payload.success) {
          throw new Error(payload.message || 'Unable to send your message right now.');
        }

        form.reset();
        setStatus(statusNode, payload.message || 'Message sent successfully.', 'success');
      } catch (error) {
        setStatus(
          statusNode,
          error instanceof Error ? error.message : 'Unable to send your message right now.',
          'error'
        );
      } finally {
        if (submitButton) {
          submitButton.disabled = false;
          submitButton.removeAttribute('aria-busy');
          submitButton.innerHTML = defaultButtonHtml;
        }
      }
    });
  });
})();
