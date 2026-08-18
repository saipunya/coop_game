(() => {
  const app = document.getElementById('quizApp');
  if (!app) return;

  const elements = {
    question: document.getElementById('questionText'),
    answerReveal: document.getElementById('answerReveal'),
    answerPlaceholder: document.getElementById('answerPlaceholder'),
    answerText: document.getElementById('answerText'),
    answerExplanation: document.getElementById('answerExplanation'),
    timer: document.getElementById('timerNumber'),
    ring: document.getElementById('timerRing'),
    hint: document.getElementById('timerHint'),
    status: document.getElementById('statusLabel'),
    progress: document.getElementById('questionProgress'),
    reveal: document.getElementById('revealButton'),
    next: document.getElementById('nextButton'),
    empty: document.getElementById('emptyState')
  };
  const category = app.dataset.category;
  const storageKey = `science-question-state-v2:${category}`;
  const questionLimit = Math.max(1, Number(app.dataset.questionLimit) || 10);
  const timeLimit = Number(app.dataset.timeLimit) || 30;
  let questionPool = [];
  let questions = [];
  let currentIndex = 0;
  let currentQuestion = null;
  let remaining = timeLimit;
  let deadline = 0;
  let revealed = false;
  let stateSignature = '';
  let interval = null;

  function shuffle(items) {
    for (let index = items.length - 1; index > 0; index -= 1) {
      const swapIndex = Math.floor(Math.random() * (index + 1));
      [items[index], items[swapIndex]] = [items[swapIndex], items[index]];
    }
    return items;
  }

  function loadSavedState() {
    try {
      return JSON.parse(localStorage.getItem(storageKey) || 'null');
    } catch {
      return null;
    }
  }

  function saveState() {
    try {
      localStorage.setItem(storageKey, JSON.stringify({
        signature: stateSignature,
        questionIds: questions.map(question => question.id),
        currentIndex,
        deadline,
        revealed
      }));
    } catch {
      // The quiz still works when private browsing blocks local storage.
    }
  }

  function updateTimer() {
    elements.timer.textContent = remaining;
    elements.ring.style.setProperty('--progress', `${Math.max(0, remaining / timeLimit * 100)}%`);
    elements.ring.classList.toggle('warning', remaining <= 10 && remaining > 5);
    elements.ring.classList.toggle('danger', remaining <= 5 && remaining > 0);
  }

  function expire() {
    clearInterval(interval);
    remaining = 0;
    updateTimer();
    elements.status.textContent = 'หมดเวลาแล้ว';
    elements.hint.textContent = 'กดปุ่มด้านล่างเพื่อดูคำตอบที่ถูกต้อง';
    elements.reveal.hidden = false;
    saveState();
  }

  function startTimer(keepDeadline) {
    clearInterval(interval);
    if (!keepDeadline || !deadline) deadline = Date.now() + timeLimit * 1000;

    const tick = () => {
      remaining = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
      updateTimer();
      if (remaining <= 0) expire();
    };

    tick();
    if (remaining > 0) interval = setInterval(tick, 250);
    saveState();
  }

  function renderQuestion(keepDeadline = false) {
    currentQuestion = questions[currentIndex];
    elements.question.textContent = currentQuestion.text;
    elements.status.textContent = 'คิดและเตรียมคำตอบของคุณ';
    elements.hint.textContent = 'คิดหาคำตอบก่อนเวลาหมด';
    elements.reveal.hidden = true;
    elements.next.hidden = true;
    elements.answerReveal.hidden = true;
    elements.answerPlaceholder.hidden = false;
    elements.answerText.textContent = '';
    elements.answerExplanation.textContent = '';
    if (elements.progress) elements.progress.textContent = `ข้อ ${currentIndex + 1} / ${questions.length}`;
    if (revealed) {
      remaining = 0;
      updateTimer();
      revealAnswer(true);
    } else {
      startTimer(keepDeadline);
    }
  }

  async function revealAnswer(restoring = false) {
    elements.reveal.disabled = true;
    try {
      const response = await fetch(`/question/api/questions/${currentQuestion.id}/answer`);
      if (!response.ok) throw new Error('answer unavailable');
      const answer = await response.json();
      revealed = true;
      elements.answerText.textContent = answer.answer;
      elements.answerExplanation.textContent = answer.explanation || '';
      elements.answerExplanation.hidden = !answer.explanation;
      elements.answerReveal.hidden = false;
      elements.answerPlaceholder.hidden = true;
      elements.status.textContent = 'แสดงเฉลยแล้ว';
      elements.hint.textContent = 'นี่คือคำตอบที่ถูกต้อง';
      elements.reveal.hidden = true;
      elements.next.hidden = false;
      elements.next.innerHTML = currentIndex + 1 >= questions.length ? 'เริ่มรอบใหม่ <span>↻</span>' : 'คำถามถัดไป <span>→</span>';
      saveState();
    } catch {
      elements.hint.textContent = 'ไม่สามารถโหลดเฉลยได้ กรุณาลองอีกครั้ง';
      if (!restoring) elements.reveal.hidden = false;
    } finally {
      elements.reveal.disabled = false;
    }
  }

  elements.reveal.addEventListener('click', () => revealAnswer(false));
  elements.next.addEventListener('click', () => {
    if (currentIndex + 1 >= questions.length) {
      questions = [...questionPool];
      if (app.dataset.randomize === 'true') shuffle(questions);
      questions = questions.slice(0, Math.min(questionLimit, questions.length));
      currentIndex = 0;
    } else {
      currentIndex += 1;
    }
    deadline = 0;
    revealed = false;
    renderQuestion(false);
  });

  fetch(`/question/api/questions?category=${encodeURIComponent(category)}`)
    .then(response => response.json())
    .then(data => {
      const availableQuestions = data.questions || [];
      if (!availableQuestions.length) {
        app.hidden = true;
        elements.empty.hidden = false;
        return;
      }

      questionPool = [...availableQuestions];
      const displayedQuestionCount = Math.min(questionLimit, availableQuestions.length);
      stateSignature = `${availableQuestions.map(question => question.id).sort((a, b) => a - b).join('-')}:${timeLimit}:${questionLimit}:${app.dataset.randomize}`;
      const saved = loadSavedState();
      const questionsById = new Map(availableQuestions.map(question => [question.id, question]));
      const canRestore = saved && saved.signature === stateSignature &&
        Array.isArray(saved.questionIds) && saved.questionIds.length === displayedQuestionCount &&
        saved.questionIds.every(id => questionsById.has(id));

      if (canRestore) {
        questions = saved.questionIds.map(id => questionsById.get(id));
        currentIndex = Math.max(0, Math.min(questions.length - 1, Number(saved.currentIndex) || 0));
        deadline = Number(saved.deadline) || 0;
        revealed = saved.revealed === true;
        renderQuestion(true);
      } else {
        questions = [...availableQuestions];
        if (app.dataset.randomize === 'true') shuffle(questions);
        questions = questions.slice(0, displayedQuestionCount);
        currentIndex = 0;
        deadline = 0;
        revealed = false;
        renderQuestion(false);
      }
    })
    .catch(() => {
      elements.question.textContent = 'ไม่สามารถโหลดคำถามได้';
      elements.status.textContent = 'เกิดข้อผิดพลาด';
      elements.hint.textContent = 'กรุณาลองรีเฟรชหน้าอีกครั้ง';
    });
})();
