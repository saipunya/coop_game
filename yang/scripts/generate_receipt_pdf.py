#!/usr/bin/env python3
import json
import os
import sys

from reportlab.lib import colors
from reportlab.lib.pagesizes import A4
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.pdfgen import canvas


def find_font():
    candidates = [
        "/System/Library/Fonts/Supplemental/Tahoma.ttf",
        "/Library/Fonts/Tahoma.ttf",
        "/usr/share/fonts/truetype/noto/NotoSansThai-Regular.ttf",
        "/usr/share/fonts/truetype/tlwg/Garuda.ttf",
    ]
    for path in candidates:
        if os.path.isfile(path):
            return path
    raise RuntimeError("Thai font not found")


def money(value):
    return f"{float(value or 0):,.2f}"


def main():
    if len(sys.argv) != 3:
        raise RuntimeError("usage: generate_receipt_pdf.py input.json output.pdf")
    with open(sys.argv[1], encoding="utf-8") as source:
        data = json.load(source)

    pdfmetrics.registerFont(TTFont("Thai", find_font()))
    c = canvas.Canvas(sys.argv[2], pagesize=A4, pageCompression=1)
    width, height = A4
    green = colors.HexColor("#176B49")
    pale = colors.HexColor("#EAF5EF")
    ink = colors.HexColor("#1B2A22")
    muted = colors.HexColor("#68766F")
    line = colors.HexColor("#D8E4DC")
    margin = 48

    c.setFillColor(green)
    c.roundRect(margin, height - 146, width - margin * 2, 86, 12, fill=1, stroke=0)
    c.setFillColor(colors.white)
    c.setFont("Thai", 19)
    c.drawString(margin + 22, height - 98, "ใบเสร็จรับเงินค่ารวบรวมยาง")
    c.setFont("Thai", 10)
    c.drawString(margin + 22, height - 119, "ระบบรวบรวมยาง สหกรณ์การเกษตร")
    c.drawRightString(width - margin - 22, height - 98, f"เลขที่ {data['id']}")
    c.drawRightString(width - margin - 22, height - 119, data["thai_date"])

    y = height - 180
    c.setFillColor(ink)
    c.setFont("Thai", 12)
    c.drawString(margin, y, "ข้อมูลผู้รวบรวม")
    y -= 23
    c.setFillColor(pale)
    c.roundRect(margin, y - 58, width - margin * 2, 66, 9, fill=1, stroke=0)
    c.setFillColor(muted)
    c.setFont("Thai", 9)
    c.drawString(margin + 16, y - 13, "ชื่อ-สกุล")
    c.drawString(margin + 315, y - 13, "ประเภท")
    c.drawString(margin + 410, y - 13, "ลานรับยาง")
    c.setFillColor(ink)
    c.setFont("Thai", 12)
    c.drawString(margin + 16, y - 36, data["fullname"][:45])
    c.drawString(margin + 315, y - 36, data["class_label"])
    c.drawString(margin + 410, y - 36, f"ลาน {data['lan']}")

    y -= 94
    c.setFont("Thai", 12)
    c.drawString(margin, y, "รายละเอียดการรับยาง")
    y -= 25
    rows = [
        ("ปริมาณยาง", f"{money(data['quantity'])} kg"),
        ("ราคาอ้างอิง", f"{money(data['price'])} บาท/kg"),
        ("มูลค่ายาง", f"{money(data['value'])} บาท"),
    ]
    for label, value in rows:
        c.setStrokeColor(line)
        c.line(margin, y - 7, width - margin, y - 7)
        c.setFillColor(muted)
        c.setFont("Thai", 10)
        c.drawString(margin + 8, y + 6, label)
        c.setFillColor(ink)
        c.setFont("Thai", 11)
        c.drawRightString(width - margin - 8, y + 6, value)
        y -= 34

    y -= 12
    c.setFillColor(ink)
    c.setFont("Thai", 12)
    c.drawString(margin, y, "รายการหัก")
    y -= 25
    deductions = [
        ("หุ้น", data["hoon"]), ("เงินกู้", data["loan"]),
        ("หนี้สั้น", data["shortdebt"]), ("เงินฝาก", data["deposit"]),
        ("ลูกหนี้การค้า", data["tradeloan"]), ("ประกันภัย", data["insurance"]),
    ]
    col_width = (width - margin * 2 - 12) / 2
    for index, (label, value) in enumerate(deductions):
        col = index % 2
        if col == 0 and index:
            y -= 42
        x = margin + col * (col_width + 12)
        c.setFillColor(colors.HexColor("#F7F9F8"))
        c.roundRect(x, y - 24, col_width, 34, 6, fill=1, stroke=0)
        c.setFillColor(muted)
        c.setFont("Thai", 9)
        c.drawString(x + 10, y - 5, label)
        c.setFillColor(ink)
        c.drawRightString(x + col_width - 10, y - 5, f"{money(value)} บาท")
    y -= 62

    c.setFillColor(pale)
    c.roundRect(margin, y - 72, width - margin * 2, 82, 10, fill=1, stroke=0)
    c.setFillColor(muted)
    c.setFont("Thai", 10)
    c.drawString(margin + 18, y - 13, "ยอดหักรวม")
    c.drawRightString(width - margin - 18, y - 13, f"{money(data['expend'])} บาท")
    c.setFillColor(green)
    c.setFont("Thai", 16)
    c.drawString(margin + 18, y - 48, "ยอดสุทธิที่ได้รับ")
    c.drawRightString(width - margin - 18, y - 48, f"{money(data['netvalue'])} บาท")

    y -= 138
    c.setStrokeColor(muted)
    c.line(margin + 25, y, margin + 205, y)
    c.line(width - margin - 205, y, width - margin - 25, y)
    c.setFillColor(muted)
    c.setFont("Thai", 9)
    c.drawCentredString(margin + 115, y - 17, "ผู้ส่งมอบยาง")
    c.drawCentredString(width - margin - 115, y - 17, "เจ้าหน้าที่ผู้รับเงิน")

    c.setFont("Thai", 8)
    c.drawCentredString(width / 2, 28, f"บันทึกโดย {data['saveby']} | วันที่บันทึก {data['savedate']}")
    c.save()


if __name__ == "__main__":
    main()
