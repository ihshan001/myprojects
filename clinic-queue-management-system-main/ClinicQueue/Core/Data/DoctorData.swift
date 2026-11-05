//
//  DoctorData.swift
//  ClinicQueue
//
//  Created by dilshan fernando on 2026-03-09.
//

import SwiftUI

struct DoctorCategoryGroup {
    let specialty: String
    let doctors: [InfoCardData]
}

struct DoctorData {

    static let doctorGroups: [DoctorCategoryGroup] = [

        DoctorCategoryGroup(
            specialty: "General",
            doctors: [
                createDoc(id: "08", name: "Dr. Sam Wilson", sub: "General Practitioner", queue: "15", wait: "15", room: "01", price: "20"),
                createDoc(id: "21", name: "Dr. Alicia Keys", sub: "Family Medicine", queue: "4", wait: "10", room: "03", price: "20")
            ]
        ),

        DoctorCategoryGroup(
            specialty: "Cardiology",
            doctors: [
                createDoc(id: "01", name: "Dr. Jane Doe", sub: "Cardiologist", queue: "12", wait: "25", room: "02", price: "25"),
                createDoc(id: "02", name: "Dr. Robert King", sub: "Cardiologist", queue: "6", wait: "10", room: "04", price: "40")
            ]
        ),

        DoctorCategoryGroup(
            specialty: "Neurology",
            doctors: [
                createDoc(id: "06", name: "Dr. Emily Clark", sub: "Neurologist", queue: "3", wait: "10", room: "08", price: "70"),
                createDoc(id: "22", name: "Dr. Xavier Brown", sub: "Neurosurgeon", queue: "1", wait: "45", room: "08B", price: "150")
            ]
        ),

        DoctorCategoryGroup(
            specialty: "Orthopedics",
            doctors: [
                createDoc(id: "07", name: "Dr. Michael Brown", sub: "Orthopedic Surgeon", queue: "9", wait: "30", room: "09", price: "80")
            ]
        ),

        DoctorCategoryGroup(
            specialty: "Pediatrics",
            doctors: [
                createDoc(id: "05", name: "Dr. Peter Pan", sub: "Pediatrician", queue: "4", wait: "5", room: "07", price: "30"),
                createDoc(id: "23", name: "Dr. Wendy Darling", sub: "Pediatric Specialist", queue: "2", wait: "15", room: "07B", price: "35")
            ]
        ),

        DoctorCategoryGroup(
            specialty: "OB-GYN",
            doctors: [
                createDoc(id: "09", name: "Dr. Maria Garcia", sub: "Obstetrician", queue: "7", wait: "40", room: "10", price: "55")
            ]
        ),

        DoctorCategoryGroup(
            specialty: "Dermatology",
            doctors: [
                createDoc(id: "03", name: "Dr. Alex Smith", sub: "Dermatologist", queue: "8", wait: "15", room: "05", price: "50"),
                createDoc(id: "04", name: "Dr. Sarah Lee", sub: "Cosmetic Dermatologist", queue: "5", wait: "20", room: "06", price: "60")
            ]
        ),

        DoctorCategoryGroup(
            specialty: "ENT",
            doctors: [
                createDoc(id: "10", name: "Dr. David Chen", sub: "Otolaryngologist", queue: "2", wait: "15", room: "11", price: "45")
            ]
        ),

        DoctorCategoryGroup(
            specialty: "Ophthalmology",
            doctors: [
                createDoc(id: "11", name: "Dr. Linda Wright", sub: "Eye Surgeon", queue: "10", wait: "20", room: "12", price: "50")
            ]
        ),

        DoctorCategoryGroup(
            specialty: "Gastroenterology",
            doctors: [
                createDoc(id: "12", name: "Dr. Steven Ross", sub: "Gastroenterologist", queue: "4", wait: "35", room: "14", price: "65")
            ]
        ),

        DoctorCategoryGroup(
            specialty: "Urology",
            doctors: [
                createDoc(id: "13", name: "Dr. Kevin Hart", sub: "Urologist", queue: "1", wait: "10", room: "15", price: "75")
            ]
        ),

        DoctorCategoryGroup(
            specialty: "General Surgery",
            doctors: [
                createDoc(id: "14", name: "Dr. Bruce Banner", sub: "Trauma Surgeon", queue: "0", wait: "0", room: "S-1", price: "120")
            ]
        ),

        DoctorCategoryGroup(
            specialty: "Psychiatry",
            doctors: [
                createDoc(id: "15", name: "Dr. Harleen Quinzel", sub: "Psychiatrist", queue: "2", wait: "50", room: "20", price: "90")
            ]
        ),

        DoctorCategoryGroup(
            specialty: "Oncology",
            doctors: [
                createDoc(id: "16", name: "Dr. Victor Stone", sub: "Oncologist", queue: "5", wait: "45", room: "22", price: "150")
            ]
        ),

        DoctorCategoryGroup(
            specialty: "Endocrinology",
            doctors: [
                createDoc(id: "17", name: "Dr. Susan Storm", sub: "Endocrinologist", queue: "3", wait: "30", room: "25", price: "$65")
            ]
        ),

        DoctorCategoryGroup(
            specialty: "Dental",
            doctors: [
                createDoc(id: "18", name: "Dr. Tony Stark", sub: "Orthodontist", queue: "6", wait: "15", room: "D-1", price: "45"),
                createDoc(id: "24", name: "Dr. Pepper Potts", sub: "Dental Surgeon", queue: "3", wait: "20", room: "D-2", price: "55")
            ]
        ),

        DoctorCategoryGroup(
            specialty: "Pulmonology",
            doctors: [
                createDoc(id: "19", name: "Dr. Barry Allen", sub: "Pulmonologist", queue: "8", wait: "10", room: "28", price: "55")
            ]
        ),

        DoctorCategoryGroup(
            specialty: "Nephrology",
            doctors: [
                createDoc(id: "20", name: "Dr. Arthur Curry", sub: "Nephrologist", queue: "4", wait: "25", room: "30", price: "70"),
                createDoc(id: "25", name: "Dr. Mera Ocean", sub: "Kidney Specialist", queue: "2", wait: "15", room: "31", price: "75")
            ]
        )
    ]


    private static func createDoc(id: String, name: String, sub: String, queue: String, wait: String, room: String, price: String) -> InfoCardData {
        return InfoCardData(
            image: Image("doctor\(id)"),
            heading: name,
            subheading: sub,
            activeQueueCount: "\(queue) patients in queue",
            detail1: ("Estimated wait:", "~\(wait) min"),
            detail2: ("Location:", "Room \(room)"),
            price: "$\(price)",
            availableDates: generateDates(days: 7),
            maxPatientsPerDay: 20
        )
    }
    
    static func leastBusyDoctor(from doctors: [InfoCardData]) -> InfoCardData? {
           return doctors.min { doctor1, doctor2 in
               let queue1 = extractQueue(doctor1.activeQueueCount ?? "0")
               let queue2 = extractQueue(doctor2.activeQueueCount ?? "0")
               return queue1 < queue2
           }
       }
}



private func extractQueue(_ queueString: String) -> Int {
    let components = queueString.components(separatedBy: " ")
    if let first = components.first, let number = Int(first) {
        return number
    }
    return Int.max
}




func generateDates(days: Int) -> [DoctorAvailability] {
    let calendar = Calendar.current
    let formatter = DateFormatter()
    formatter.dateFormat = "EEEE"

    var items: [DoctorAvailability] = []
    let today = Date()

    for i in 0..<days {
        guard let date = calendar.date(byAdding: .day, value: i, to: today) else { continue }

        let weekday: String
        if calendar.isDateInToday(date) {
            weekday = "Today" 
        } else {
            weekday = formatter.string(from: date)
        }

        let timeRange = "17:30 pm - 20:30 pm"
        let icon = Image(systemName: "calendar")
        let queueLabel = "\(i + 1)"

        let availability = DoctorAvailability(
            weekday: weekday,
            timeRange: timeRange,
            icon: icon,
            queueLabel: queueLabel,
            date: date
        )

        items.append(availability)
    }

    return items
}
