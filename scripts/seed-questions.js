#!/usr/bin/env node

/**
 * Script to insert sample questions
 * Usage: node scripts/seed-questions.js
 */

require('dotenv').config();
const pool = require('../config/database');

async function seedQuestions() {
  try {
    console.log('Connecting to database...');
    await pool.query('USE ??', [process.env.DB_NAME || 'coopgame_db']);

    // Clear existing questions
    console.log('Clearing existing questions...');
    await pool.query('DELETE FROM questions');
    console.log('✅ Cleared old questions');

    console.log('Inserting sample questions...');

    const questions = [
      // Easy questions (10s) - 15 questions
      {
        question_text: 'สหกรณ์คืออะไร?',
        option_a: 'กลุ่มการค้า',
        option_b: 'องค์กรทางเศรษฐกิจ',
        option_c: 'บริษัทเอกชน',
        option_d: 'หน่วยงานราชการ',
        correct_answer: 'B',
        difficulty: 'easy',
        time_limit: 10,
        is_active: true
      },
      {
        question_text: 'สหกรณ์มีกี่ประเภทหลัก?',
        option_a: '2 ประเภท',
        option_b: '3 ประเภท',
        option_c: '4 ประเภท',
        option_d: '5 ประเภท',
        correct_answer: 'C',
        difficulty: 'easy',
        time_limit: 10,
        is_active: true
      },
      {
        question_text: 'หลักการสหกรณ์มีกี่ข้อ?',
        option_a: '5 ข้อ',
        option_b: '6 ข้อ',
        option_c: '7 ข้อ',
        option_d: '8 ข้อ',
        correct_answer: 'C',
        difficulty: 'easy',
        time_limit: 10,
        is_active: true
      },
      {
        question_text: 'สหกรณ์ต้องการสมาชิกขั้นต่ำกี่คน?',
        option_a: '5 คน',
        option_b: '10 คน',
        option_c: '15 คน',
        option_d: '20 คน',
        correct_answer: 'C',
        difficulty: 'easy',
        time_limit: 10,
        is_active: true
      },
      {
        question_text: 'สหกรณ์แบ่งปันผลกันอย่างไร?',
        option_a: 'ตามหุ้น',
        option_b: 'ตามการใช้บริการ',
        option_c: 'เท่ากันทุกคน',
        option_d: 'ตามระดับ',
        correct_answer: 'B',
        difficulty: 'easy',
        time_limit: 10,
        is_active: true
      },
      {
        question_text: 'ใครเป็นเจ้าของสหกรณ์?',
        option_a: 'รัฐบาล',
        option_b: 'สมาชิก',
        option_c: 'คณะกรรมการ',
        option_d: 'นายทุน',
        correct_answer: 'B',
        difficulty: 'easy',
        time_limit: 10,
        is_active: true
      },
      {
        question_text: 'สหกรณ์จัดตั้งขึ้นเพื่อวัตถุประสงค์อะไร?',
        option_a: 'หากำไรสูงสุด',
        option_b: 'ช่วยเหลือสมาชิก',
        option_c: 'แข่งขันกับเอกชน',
        option_d: 'เก็บภาษี',
        correct_answer: 'B',
        difficulty: 'easy',
        time_limit: 10,
        is_active: true
      },
      {
        question_text: 'การเป็นสมาชิกสหกรณ์เป็นอย่างไร?',
        option_a: 'บังคับ',
        option_b: 'สมัครใจ',
        option_c: 'ตามหุ้น',
        option_d: 'ตามอายุ',
        correct_answer: 'B',
        difficulty: 'easy',
        time_limit: 10,
        is_active: true
      },
      {
        question_text: 'สหกรณ์มีการบริหารแบบใด?',
        option_a: 'รวมศูนย์',
        option_b: 'กระจายอำนาจ',
        option_c: 'ประชาธิปไตย',
        option_d: 'เผด็จการ',
        correct_answer: 'C',
        difficulty: 'easy',
        time_limit: 10,
        is_active: true
      },
      {
        question_text: 'สหกรณ์ประเภทใดพบมากที่สุดในไทย?',
        option_a: 'สหกรณ์การเกษตร',
        option_b: 'สหกรณ์เครดิต',
        option_c: 'สหกรณ์ร้านค้า',
        option_d: 'สหกรณ์บริการ',
        correct_answer: 'A',
        difficulty: 'easy',
        time_limit: 10,
        is_active: true
      },
      {
        question_text: 'หุ้นสหกรณ์มีลักษณะอย่างไร?',
        option_a: 'ซื้อขายได้',
        option_b: 'ถอนได้ตลอด',
        option_c: 'ไม่เปลี่ยนมือ',
        option_d: 'มีดอกเบี้ยสูง',
        correct_answer: 'C',
        difficulty: 'easy',
        time_limit: 10,
        is_active: true
      },
      {
        question_text: 'สหกรณ์ต้องจดทะเบียนกับหน่วยงานใด?',
        option_a: 'กรมพาณิชย์',
        option_b: 'กรมส่งเสริมสหกรณ์',
        option_c: 'กรมที่ดิน',
        option_d: 'กรมบัญชีกลาง',
        correct_answer: 'B',
        difficulty: 'easy',
        time_limit: 10,
        is_active: true
      },
      {
        question_text: 'สหกรณ์มีสภาพเป็นอะไร?',
        option_a: 'นิติบุคคล',
        option_b: 'บุคคลธรรมดา',
        option_c: 'ห้างหุ้นส่วน',
        option_d: 'กลุ่มบุคคล',
        correct_answer: 'A',
        difficulty: 'easy',
        time_limit: 10,
        is_active: true
      },
      {
        question_text: 'คำขวัญสหกรณ์คืออะไร?',
        option_a: 'รวมพลัง สร้างสรรค์',
        option_b: 'ช่วยตัวเอง ช่วยกัน',
        option_c: 'เพื่อนช่วยเพื่อน',
        option_d: 'ร่วมใจ ร่วมทาง',
        correct_answer: 'B',
        difficulty: 'easy',
        time_limit: 10,
        is_active: true
      },
      {
        question_text: 'สหกรณ์มีที่มาจากภาษาใด?',
        option_a: 'ไทย',
        option_b: 'บาลี',
        option_c: 'อังกฤษ',
        option_d: 'สันสกฤต',
        correct_answer: 'C',
        difficulty: 'easy',
        time_limit: 10,
        is_active: true
      },

      // Medium questions (15s) - 15 questions
      {
        question_text: 'กรมส่งเสริมสหกรณ์สังกัดกระทรวงใด?',
        option_a: 'กระทรวงเกษตรและสหกรณ์',
        option_b: 'กระทรวงมหาดไทย',
        option_c: 'กระทรวงพาณิชย์',
        option_d: 'กระทรวงการคลัง',
        correct_answer: 'A',
        difficulty: 'medium',
        time_limit: 15,
        is_active: true
      },
      {
        question_text: 'สหกรณ์เครดิตยูเนียนมีหน้าที่หลักอะไร?',
        option_a: 'ผลิตสินค้า',
        option_b: 'ให้กู้ยืมเงิน',
        option_c: 'จำหน่ายสินค้า',
        option_d: 'ให้บริการท่องเที่ยว',
        correct_answer: 'B',
        difficulty: 'medium',
        time_limit: 15,
        is_active: true
      },
      {
        question_text: 'กลุ่มเกษตรกรต้องการจดทะเบียนเป็นนิติบุคคลควรทำอย่างไร?',
        option_a: 'จดทะเบียนบริษัท',
        option_b: 'จดทะเบียนวิสาหกิจชุมชน',
        option_c: 'จดทะเบียนสหกรณ์',
        option_d: 'ไม่ต้องจดทะเบียน',
        correct_answer: 'C',
        difficulty: 'medium',
        time_limit: 15,
        is_active: true
      },
      {
        question_text: 'ทุนจดทะเบียนขั้นต่ำของสหกรณ์ประเภทเครดิตคือเท่าใด?',
        option_a: '100,000 บาท',
        option_b: '500,000 บาท',
        option_c: '1,000,000 บาท',
        option_d: 'ไม่กำหนด',
        correct_answer: 'B',
        difficulty: 'medium',
        time_limit: 15,
        is_active: true
      },
      {
        question_text: 'อัตราส่วนการออกเสียงในที่ประชุมสหกรณ์คืออย่างไร?',
        option_a: '1 คน 1 เสียง',
        option_b: 'ตามจำนวนหุ้น',
        option_c: 'ตามอายุสมาชิก',
        option_d: 'คณะกรรมการตัดสิน',
        correct_answer: 'A',
        difficulty: 'medium',
        time_limit: 15,
        is_active: true
      },
      {
        question_text: 'สหกรณ์ต้องจัดประชุมใหญ่สมาชิกปีละกี่ครั้ง?',
        option_a: 'ไม่กำหนด',
        option_b: '1 ครั้ง',
        option_c: '2 ครั้ง',
        option_d: '4 ครั้ง',
        correct_answer: 'B',
        difficulty: 'medium',
        time_limit: 15,
        is_active: true
      },
      {
        question_text: 'ผู้บริหารสหกรณ์คือใคร?',
        option_a: 'นายทุน',
        option_b: 'คณะกรรมการ',
        option_c: 'เจ้าหน้าที่รัฐ',
        option_d: 'ผู้จัดการ',
        correct_answer: 'B',
        difficulty: 'medium',
        time_limit: 15,
        is_active: true
      },
      {
        question_text: 'สหกรณ์สามารถประกอบกิจการใดได้?',
        option_a: 'เฉพาะที่กำหนด',
        option_b: 'ทุกกิจการ',
        option_c: 'เฉพาะการเกษตร',
        option_d: 'เฉพาะการเงิน',
        correct_answer: 'A',
        difficulty: 'medium',
        time_limit: 15,
        is_active: true
      },
      {
        question_text: 'การตรวจสอบบัญชีสหกรณ์ทำโดยใคร?',
        option_a: 'สมาชิก',
        option_b: 'ผู้สอบบัญชี',
        option_c: 'กรมสรรพากร',
        option_d: 'คณะกรรมการ',
        correct_answer: 'B',
        difficulty: 'medium',
        time_limit: 15,
        is_active: true
      },
      {
        question_text: 'สหกรณ์มีกี่ประเภทในไทย?',
        option_a: '2 ประเภท',
        option_b: '4 ประเภท',
        option_c: '6 ประเภท',
        option_d: '8 ประเภท',
        correct_answer: 'C',
        difficulty: 'medium',
        time_limit: 15,
        is_active: true
      },
      {
        question_text: 'เงินสำรองของสหกรณ์ต้องตั้งเป็นกี่เปอร์เซ็นต์?',
        option_a: '5%',
        option_b: '10%',
        option_c: '15%',
        option_d: '20%',
        correct_answer: 'B',
        difficulty: 'medium',
        time_limit: 15,
        is_active: true
      },
      {
        question_text: 'สหกรณ์ร้านค้ามีวัตถุประสงค์หลักอะไร?',
        option_a: 'หากำไรสูง',
        option_b: 'จำหน่ายสินค้าแก่สมาชิก',
        option_c: 'ส่งออกสินค้า',
        option_d: 'นำเข้าสินค้า',
        correct_answer: 'B',
        difficulty: 'medium',
        time_limit: 15,
        is_active: true
      },
      {
        question_text: 'สหกรณ์บริการมีหน้าที่อะไร?',
        option_a: 'ผลิตสินค้า',
        option_b: 'ให้บริการแก่สมาชิก',
        option_c: 'กู้ยืมเงิน',
        option_d: 'จำหน่ายสินค้า',
        correct_answer: 'B',
        difficulty: 'medium',
        time_limit: 15,
        is_active: true
      },
      {
        question_text: 'สหกรณ์ประมงมีลักษณะเฉพาะอย่างไร?',
        option_a: 'เลี้ยงสัตว์น้ำ',
        option_b: 'จับสัตว์น้ำ',
        option_c: 'แปรรูปสัตว์น้ำ',
        option_d: 'ทั้งหมดถูกต้อง',
        correct_answer: 'D',
        difficulty: 'medium',
        time_limit: 15,
        is_active: true
      },
      {
        question_text: 'สหกรณ์นิคมมีวัตถุประสงค์อะไร?',
        option_a: 'จัดหาที่ดิน',
        option_b: 'จัดหาที่อยู่อาศัย',
        option_c: 'จัดหางาน',
        option_d: 'จัดหาอาหาร',
        correct_answer: 'B',
        difficulty: 'medium',
        time_limit: 15,
        is_active: true
      },

      // Hard questions (20s) - 15 questions
      {
        question_text: 'พระราชบัญญัติสหกรณ์ พ.ศ. 2542 มีผู้รับจัดตั้งสหกรณ์ขั้นต่ำกี่คน?',
        option_a: '5 คน',
        option_b: '10 คน',
        option_c: '15 คน',
        option_d: '20 คน',
        correct_answer: 'C',
        difficulty: 'hard',
        time_limit: 20,
        is_active: true
      },
      {
        question_text: 'สหกรณ์ต้องจัดทำบัญชีรายปีทุกปีภายในกี่เดือนหลังสิ้นปีบัญชี?',
        option_a: '3 เดือน',
        option_b: '4 เดือน',
        option_c: '6 เดือน',
        option_d: '12 เดือน',
        correct_answer: 'B',
        difficulty: 'hard',
        time_limit: 20,
        is_active: true
      },
      {
        question_text: 'คณะกรรมการสหกรณ์มีวาระการดำรงตำแหน่งกี่ปี?',
        option_a: '1 ปี',
        option_b: '2 ปี',
        option_c: '3 ปี',
        option_d: '4 ปี',
        correct_answer: 'D',
        difficulty: 'hard',
        time_limit: 20,
        is_active: true
      },
      {
        question_text: 'สมาชิกสหกรณ์สามารถถอนหุ้นได้หรือไม่?',
        option_a: 'ได้ทุกเวลา',
        option_b: 'ได้เฉพาะสิ้นปี',
        option_c: 'ไม่ได้',
        option_d: 'ได้เฉพาะเมื่อออกจากสหกรณ์',
        correct_answer: 'C',
        difficulty: 'hard',
        time_limit: 20,
        is_active: true
      },
      {
        question_text: 'กำไรของสหกรณ์ต้องนำไปทำอะไรก่อน?',
        option_a: 'แบ่งปันกันทันที',
        option_b: 'จ่ายค่าภาษีก่อน',
        option_c: 'สำรองเผื่อผันก่อน',
        option_d: 'จ่ายดอกเบี้ยเงินกู้ก่อน',
        correct_answer: 'D',
        difficulty: 'hard',
        time_limit: 20,
        is_active: true
      },
      {
        question_text: 'การควบรวมสหกรณ์ต้องได้รับความเห็นชอบจากที่ประชุมใหญ่กี่เปอร์เซ็นต์?',
        option_a: '50%',
        option_b: '60%',
        option_c: '70%',
        option_d: '75%',
        correct_answer: 'D',
        difficulty: 'hard',
        time_limit: 20,
        is_active: true
      },
      {
        question_text: 'สหกรณ์ต้องมีผู้สอบบัญชีกี่คน?',
        option_a: '1 คน',
        option_b: '2 คน',
        option_c: '3 คน',
        option_d: 'ไม่กำหนด',
        correct_answer: 'A',
        difficulty: 'hard',
        time_limit: 20,
        is_active: true
      },
      {
        question_text: 'การเลือกตั้งคณะกรรมการสหกรณ์ใช้วิธีใด?',
        option_a: 'เสียงข้างมาก',
        option_b: 'เสียงเดียว',
        option_c: 'เลือกตั้งโดยตรง',
        option_d: 'ทั้งหมดถูกต้อง',
        correct_answer: 'A',
        difficulty: 'hard',
        time_limit: 20,
        is_active: true
      },
      {
        question_text: 'สหกรณ์สามารถเพิกถอนความเป็นสมาชิกได้กรณีใด?',
        option_a: 'ไม่จ่ายค่าหุ้น',
        option_b: 'ไม่มาประชุม',
        option_c: 'ทำผิดข้อบังคับ',
        option_d: 'ทั้งหมดถูกต้อง',
        correct_answer: 'D',
        difficulty: 'hard',
        time_limit: 20,
        is_active: true
      },
      {
        question_text: 'เงินปันผลของสหกรณ์คิดจากอะไร?',
        option_a: 'จากหุ้น',
        option_b: 'จากการใช้บริการ',
        option_c: 'จากทุนจดทะเบียน',
        option_d: 'ทั้ง A และ B',
        correct_answer: 'D',
        difficulty: 'hard',
        time_limit: 20,
        is_active: true
      },
      {
        question_text: 'สหกรณ์ต้องจดทะเบียนภายในกี่วันหลังประชุมใหญ่?',
        option_a: '30 วัน',
        option_b: '60 วัน',
        option_c: '90 วัน',
        option_d: '120 วัน',
        correct_answer: 'A',
        difficulty: 'hard',
        time_limit: 20,
        is_active: true
      },
      {
        question_text: 'การยุบสหกรณ์ต้องได้รับความเห็นชอบจากใคร?',
        option_a: 'คณะกรรมการ',
        option_b: 'สมาชิก',
        option_c: 'นายทะเบียน',
        option_d: 'ทั้งหมดถูกต้อง',
        correct_answer: 'D',
        difficulty: 'hard',
        time_limit: 20,
        is_active: true
      },
      {
        question_text: 'สหกรณ์ต้องเสียภาษีอะไรบ้าง?',
        option_a: 'ภาษีเงินได้',
        option_b: 'ภาษีมูลค่าเพิ่ม',
        option_c: 'ภาษีธุรกิจเฉพาะ',
        option_d: 'ได้รับยกเว้นบางส่วน',
        correct_answer: 'D',
        difficulty: 'hard',
        time_limit: 20,
        is_active: true
      },
      {
        question_text: 'การก่อตั้งสหกรณ์ต้องมีผู้รับจัดตั้งกี่คน?',
        option_a: '10 คน',
        option_b: '15 คน',
        option_c: '20 คน',
        option_d: '30 คน',
        correct_answer: 'B',
        difficulty: 'hard',
        time_limit: 20,
        is_active: true
      },
      {
        question_text: 'สหกรณ์ต้องมีข้อบังคับกี่ฉบับ?',
        option_a: '1 ฉบับ',
        option_b: '2 ฉบับ',
        option_c: '3 ฉบับ',
        option_d: 'ไม่จำกัด',
        correct_answer: 'A',
        difficulty: 'hard',
        time_limit: 20,
        is_active: true
      }
    ];

    for (const q of questions) {
      await pool.query(
        `INSERT INTO questions 
         (question_text, option_a, option_b, option_c, option_d, correct_answer, difficulty, time_limit, is_active) 
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)`,
        [q.question_text, q.option_a, q.option_b, q.option_c, q.option_d, 
         q.correct_answer, q.difficulty, q.time_limit, q.is_active]
      );
      console.log(`✅ Inserted: ${q.question_text.substring(0, 30)}...`);
    }

    console.log('\n✅ Successfully inserted 15 sample questions!');
    process.exit(0);

  } catch (error) {
    console.error('❌ Error:', error.message);
    process.exit(1);
  }
}

seedQuestions();
