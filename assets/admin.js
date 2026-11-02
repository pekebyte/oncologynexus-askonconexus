/**
 * Ask OncoNexus — admin behaviour.
 *
 * Vanilla JS on purpose: no build step, no dependency on React or jQuery.
 */
(function () {
	'use strict';

	if (typeof window.OnxAskAdmin === 'undefined') {
		return;
	}

	var config = window.OnxAskAdmin;
	var strings = config.strings || {};
	var restUrl = String(config.restUrl || '').replace(/\/?$/, '/');

	function api(path, options) {
		options = options || {};

		return fetch(restUrl + path, {
			method: options.method || 'GET',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': config.nonce || '',
			},
			body: options.body ? JSON.stringify(options.body) : undefined,
		}).then(function (response) {
			return response.json().catch(function () {
				return {};
			}).then(function (payload) {
				if (!response.ok) {
					var message = payload && payload.message ? payload.message : strings.error || 'Request failed.';
					var error = new Error(message);
					error.status = response.status;
					error.payload = payload;
					throw error;
				}
				return payload;
			});
		});
	}

	function setResult(el, message, state) {
		if (!el) {
			return;
		}
		el.textContent = message || '';
		el.className = 'onx-ask-inline-result' + (state ? ' is-' + state : '');
	}

	function escapeHtml(value) {
		return String(value)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}

	/**
	 * Minimal, safe renderer for the model answer: paragraphs, bullet lists and
	 * [n] citation chips that link to the matching source.
	 */
	function renderAnswer(container, payload) {
		if (!container) {
			return;
		}

		payload = payload && typeof payload === 'object' ? payload : {};

		container.innerHTML = '';
		container.hidden = false;

		var sources = Array.isArray(payload.sources) ? payload.sources : [];
		var byIndex = {};
		sources.forEach(function (source) {
			byIndex[String(source.index)] = source;
		});

		var answer = String(payload.answer || '').trim();
		if (!answer) {
			container.textContent = strings.noAnswer || 'The assistant returned no answer.';
			return;
		}

		var blocks = answer.split(/\n{2,}/);

		blocks.forEach(function (block) {
			var lines = block.split(/\n/).map(function (line) {
				return line.trim();
			}).filter(Boolean);

			if (!lines.length) {
				return;
			}

			var isBullet = lines.every(function (line) {
				return /^[-*•]\s+/.test(line);
			});
			var isNumbered = lines.every(function (line) {
				return /^\d+[.)]\s+/.test(line);
			});

			if (isBullet || isNumbered) {
				var list = document.createElement(isNumbered ? 'ol' : 'ul');
				lines.forEach(function (line) {
					var item = document.createElement('li');
					item.innerHTML = formatInline(line.replace(/^([-*•]|\d+[.)])\s+/, ''), byIndex);
					list.appendChild(item);
				});
				container.appendChild(list);
				return;
			}

			var paragraph = document.createElement('p');
			paragraph.innerHTML = formatInline(lines.join(' '), byIndex);
			container.appendChild(paragraph);
		});

		if (sources.length) {
			var wrapper = document.createElement('div');
			wrapper.className = 'onx-ask-answer-sources';

			var heading = document.createElement('h4');
			heading.textContent = 'Sources';
			wrapper.appendChild(heading);

			var list = document.createElement('ol');
			sources.forEach(function (source) {
				var item = document.createElement('li');
				var link = document.createElement('a');
				link.href = source.url || '#';
				link.target = '_blank';
				link.rel = 'noopener noreferrer';
				link.textContent = source.title || source.url || 'Source';
				item.appendChild(link);

				if (source.type_label) {
					var type = document.createElement('span');
					type.textContent = ' · ' + source.type_label;
					type.style.color = '#646970';
					item.appendChild(type);
				}
				list.appendChild(item);
			});
			wrapper.appendChild(list);
			container.appendChild(wrapper);
		}

		var meta = document.createElement('div');
		meta.className = 'onx-ask-answer-meta';
		var bits = [];
		if (payload.provider) {
			bits.push(payload.provider + (payload.model ? ' / ' + payload.model : ''));
		}
		if (payload.cached) {
			bits.push('cached');
		}
		if (payload.search_query) {
			bits.push('search: ' + payload.search_query);
		}
		meta.textContent = bits.join(' · ');
		container.appendChild(meta);
	}

	function formatInline(text, byIndex) {
		var escaped = escapeHtml(text);
		return escaped.replace(/\[(\d{1,2})\]/g, function (match, number) {
			var source = byIndex[number];
			if (!source) {
				return '<span class="onx-ask-cite">' + number + '</span>';
			}
			return '<a class="onx-ask-cite" href="' + escapeHtml(source.url || '#') +
				'" target="_blank" rel="noopener noreferrer" title="' +
				escapeHtml(source.title || '') + '">' + number + '</a>';
		});
	}

	// -----------------------------------------------------------------------
	// Index page: reindex / flush / test / live polling
	// -----------------------------------------------------------------------

	var pollTimer = null;

	function updateProgress(status) {
		var wrap = document.querySelector('[data-onx-progress-wrap]');
		if (!wrap) {
			return;
		}

		if (!status.running) {
			wrap.hidden = true;
			if (pollTimer) {
				window.clearInterval(pollTimer);
				pollTimer = null;
			}
		} else {
			wrap.hidden = false;
			var bar = wrap.querySelector('[data-onx-progress-bar]');
			var text = wrap.querySelector('[data-onx-progress-text]');
			if (bar) {
				bar.style.width = Math.max(0, Math.min(100, Number(status.percent) || 0)) + '%';
			}
			if (text) {
				text.textContent = 'Indexing ' + (status.queued_done || 0) + ' of ' + (status.queued_total || 0) + ' posts…';
			}
		}

		var stats = status.stats || {};
		var map = {
			documents: status.indexed_posts,
			chunks: status.chunks,
			embedded: status.embedded_chunks,
		};

		Object.keys(map).forEach(function (key) {
			var el = document.querySelector('[data-onx-stat="' + key + '"]');
			if (el && typeof map[key] !== 'undefined') {
				el.textContent = String(map[key]);
			}
		});

		var coverage = document.querySelector('[data-onx-stat="coverage"]');
		if (coverage && Number(status.indexable_posts) > 0) {
			var percent = Math.min(100, Math.round((Number(status.indexed_posts) / Number(status.indexable_posts)) * 100));
			coverage.textContent = percent + '%';
		}

		if (stats && typeof stats.missing_vectors !== 'undefined' && Number(stats.missing_vectors) > 0) {
			// Surface the warning without a full page reload.
			var notice = document.querySelector('[data-onx-vector-warning]');
			if (!notice) {
				notice = document.createElement('div');
				notice.className = 'notice notice-warning inline';
				notice.setAttribute('data-onx-vector-warning', '1');
				var wrapCard = document.querySelector('.onx-ask-card');
				if (wrapCard) {
					wrapCard.parentNode.insertBefore(notice, wrapCard.nextSibling);
				}
			}
			notice.innerHTML = '<p><strong>Missing embeddings:</strong> ' + Number(stats.missing_vectors) +
				' chunk(s) have no vector. Check the provider key and reindex with force.</p>';
		}
	}

	function startPolling() {
		if (pollTimer) {
			return;
		}

		var poll = function () {
			api('ask/index-status').then(updateProgress).catch(function () {
				if (pollTimer) {
					window.clearInterval(pollTimer);
					pollTimer = null;
				}
			});
		};

		poll();
		pollTimer = window.setInterval(poll, 5000);
	}

	// -----------------------------------------------------------------------
	// Playground
	// -----------------------------------------------------------------------

	function askQuestion(question, statusEl, answerEl) {
		if (!question) {
			return;
		}

		setResult(statusEl, '', 'busy');
		if (statusEl) {
			statusEl.innerHTML = '<span class="onx-ask-spinner"></span>' + (strings.working || 'Working…');
			statusEl.className = 'onx-ask-inline-result is-busy';
		}

		api('ask', {
			method: 'POST',
			body: { question: question },
		}).then(function (payload) {
			setResult(statusEl, 'Answer received.', 'ok');
			renderAnswer(answerEl, payload);
		}).catch(function (error) {
			setResult(statusEl, error.message || strings.error || 'Something went wrong.', 'error');
			if (answerEl) {
				answerEl.hidden = true;
			}
		});
	}

	// -----------------------------------------------------------------------
	// Event wiring
	// -----------------------------------------------------------------------

	document.addEventListener('click', function (event) {
		var button = event.target.closest('[data-onx-action]');
		if (!button) {
			return;
		}

		var action = button.getAttribute('data-onx-action');
		var resultEl = button.closest('.onx-ask-card') ?
			button.closest('.onx-ask-card').querySelector('[data-onx-result]') :
			document.querySelector('[data-onx-result]');

		if (action === 'test') {
			event.preventDefault();
			var target = button.getAttribute('data-target') || 'chat';
			setResult(resultEl, strings.working || 'Working…', 'busy');
			api('ask/test', {
				method: 'POST',
				body: { target: target },
			}).then(function (payload) {
				setResult(resultEl, payload.message || 'OK', payload.ok ? 'ok' : 'error');
			}).catch(function (error) {
				setResult(resultEl, error.message || strings.error, 'error');
			});
			return;
		}

		if (action === 'reindex') {
			event.preventDefault();
			var mode = button.getAttribute('data-mode') || 'all';
			setResult(resultEl, strings.working || 'Working…', 'busy');
			api('ask/reindex', {
				method: 'POST',
				body: { mode: mode },
			}).then(function (payload) {
				setResult(resultEl, 'Queued ' + (payload.queued || 0) + ' post(s).', 'ok');
				startPolling();
			}).catch(function (error) {
				setResult(resultEl, error.message || strings.error, 'error');
			});
			return;
		}

		if (action === 'flush') {
			event.preventDefault();
			if (!window.confirm(strings.confirmFlush || 'Delete the index?')) {
				return;
			}
			setResult(resultEl, strings.working || 'Working…', 'busy');
			api('ask/flush', { method: 'POST' }).then(function () {
				setResult(resultEl, 'Index flushed.', 'ok');
				window.location.reload();
			}).catch(function (error) {
				setResult(resultEl, error.message || strings.error, 'error');
			});
		}
	});

	document.addEventListener('submit', function (event) {
		var form = event.target.closest('[data-onx-playground-form]');
		if (!form) {
			return;
		}

		event.preventDefault();
		var textarea = form.querySelector('textarea[name="question"]');
		var statusEl = document.querySelector('[data-onx-playground-status]');
		var answerEl = document.querySelector('[data-onx-playground-answer]');
		var question = textarea ? textarea.value.trim() : '';

		if (!question) {
			setResult(statusEl, 'Type a question first.', 'error');
			return;
		}

		askQuestion(question, statusEl, answerEl);
	});

	document.addEventListener('click', function (event) {
		var suggestion = event.target.closest('[data-onx-suggestion]');
		if (!suggestion) {
			return;
		}

		var question = suggestion.getAttribute('data-onx-suggestion') || '';
		var textarea = document.getElementById('onx-playground-question');
		var statusEl = document.querySelector('[data-onx-playground-status]');
		var answerEl = document.querySelector('[data-onx-playground-answer]');

		if (textarea) {
			textarea.value = question;
		}
		askQuestion(question, statusEl, answerEl);
	});

	// Start polling when the index tab is open and a queue is active.
	document.addEventListener('DOMContentLoaded', function () {
		var wrap = document.querySelector('[data-onx-progress-wrap]');
		if (wrap && !wrap.hidden) {
			startPolling();
		}
	});
})();
