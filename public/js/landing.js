document.addEventListener('DOMContentLoaded', () => {
  const form = document.getElementById('contactForm');
  const msg = document.getElementById('contactMessage');
  if (!form) return;

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    msg.textContent = 'กำลังส่ง...';
    msg.style.color = '';

    const data = {
      name: form.elements['name'].value,
      email: form.elements['email'].value,
      company: form.elements['company'].value,
      message: form.elements['message'].value,
    };

    try {
      const res = await fetch('/contact', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
      });
      const json = await res.json();
      if (json.success) {
        msg.textContent = 'ส่งข้อความเรียบร้อยแล้ว — ทีมจะติดต่อกลับเร็ว ๆ นี้';
        msg.style.color = 'green';
        form.reset();
      } else {
        msg.textContent = json.message || 'ไม่สามารถส่งข้อมูลได้ กรุณาลองอีกครั้ง';
        msg.style.color = 'crimson';
      }
    } catch (err) {
      console.error(err);
      msg.textContent = 'เกิดข้อผิดพลาดขณะส่ง';
      msg.style.color = 'crimson';
    }
  });
});
