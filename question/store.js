const fs = require('fs/promises');
const path = require('path');

const dataDirectory = path.join(__dirname, 'data');
const dataFile = path.join(dataDirectory, 'question-data.json');

const seedData = {
  settings: {
    title: 'ภารกิจพิชิตวิทยาศาสตร์',
    subtitle: 'สัปดาห์แห่งวิทยาศาสตร์ · คิดให้สนุก ปลุกพลังนักค้นคว้า',
    welcomeTitle: 'พร้อมออกเดินทางสู่โลกวิทยาศาสตร์หรือยัง?',
    welcomeText: 'ท้าทายความรู้รอบตัว คิด วิเคราะห์ และรอเปิดเฉลยไปพร้อมกันเมื่อหมดเวลา',
    timeLimit: 30,
    questionsPerRound: 10,
    randomize: true,
    showProgress: true,
    accentColor: '#6c4cff'
  },
  questions: [
    {
      id: 1,
      category: 'upper-primary',
      text: 'ดาวเคราะห์ดวงใดได้รับฉายาว่า “ดาวเคราะห์สีแดง”?',
      answer: 'ดาวอังคาร',
      explanation: 'ดาวอังคารมีพื้นผิวที่เต็มไปด้วยเหล็กออกไซด์ จึงมองเห็นเป็นสีแดง',
      active: true,
      createdAt: new Date().toISOString()
    },
    {
      id: 2,
      category: 'lower-secondary',
      text: 'หน่วยพื้นฐานที่เล็กที่สุดของสิ่งมีชีวิตคืออะไร?',
      answer: 'เซลล์',
      explanation: 'เซลล์เป็นหน่วยโครงสร้างและการทำงานพื้นฐานที่เล็กที่สุดของสิ่งมีชีวิต',
      active: true,
      createdAt: new Date().toISOString()
    },
    {
      id: 3,
      category: 'lower-secondary',
      text: 'แสงเดินทางได้เร็วที่สุดในตัวกลางชนิดใด?',
      answer: 'สุญญากาศ',
      explanation: 'แสงเดินทางในสุญญากาศด้วยความเร็วประมาณ 300,000 กิโลเมตรต่อวินาที',
      active: true,
      createdAt: new Date().toISOString()
    }
  ]
};

let writeQueue = Promise.resolve();

async function ensureStore() {
  await fs.mkdir(dataDirectory, { recursive: true });
  try {
    await fs.access(dataFile);
  } catch {
    await fs.writeFile(dataFile, JSON.stringify(seedData, null, 2), 'utf8');
  }
}

async function readStore() {
  await ensureStore();
  const raw = await fs.readFile(dataFile, 'utf8');
  const data = JSON.parse(raw);
  data.settings = { ...seedData.settings, ...(data.settings || {}) };
  if (data.settings.title === 'คำถามประจำวัน') data.settings.title = seedData.settings.title;
  if (data.settings.subtitle === 'เลือกคำตอบที่ถูกต้องก่อนหมดเวลา') data.settings.subtitle = seedData.settings.subtitle;
  if ((data.settings.welcomeText || '').includes('คำถาม 4 ตัวเลือก')) data.settings.welcomeText = seedData.settings.welcomeText;
  const legacySeedTexts = [
    'ข้อใดคือหลักการสำคัญของสหกรณ์?',
    'สมาชิกสหกรณ์มีสิทธิออกเสียงตามหลักใด?',
    'ข้อใดช่วยให้การทำงานเป็นทีมมีประสิทธิภาพมากที่สุด?'
  ];
  data.questions = (data.questions || []).map(question => {
    const legacyIndex = legacySeedTexts.indexOf(question.text);
    let migrated = legacyIndex >= 0 ? { ...seedData.questions[legacyIndex], createdAt: question.createdAt } : question;
    const currentSeed = seedData.questions.find(seedQuestion => seedQuestion.id === migrated.id && seedQuestion.text === migrated.text);
    if (currentSeed && !migrated.category) migrated = { ...migrated, category: currentSeed.category };
    if (!migrated.answer && Array.isArray(migrated.choices)) {
      migrated.answer = migrated.choices[Number(migrated.correctIndex) || 0] || '';
    }
    delete migrated.choices;
    delete migrated.correctIndex;
    if (!['upper-primary', 'lower-secondary'].includes(migrated.category)) migrated.category = 'lower-secondary';
    return migrated;
  });
  return data;
}

function updateStore(updater) {
  writeQueue = writeQueue.catch(() => undefined).then(async () => {
    const data = await readStore();
    const updated = await updater(data) || data;
    const tempFile = `${dataFile}.tmp`;
    await fs.writeFile(tempFile, JSON.stringify(updated, null, 2), 'utf8');
    await fs.rename(tempFile, dataFile);
    return updated;
  });
  return writeQueue;
}

module.exports = { readStore, updateStore };
