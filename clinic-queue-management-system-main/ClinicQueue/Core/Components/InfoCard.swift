//
//  InfoCard.swift
//  ClinicQueue
//
//  Created by dilshan fernando on 2026-03-07.
//

import SwiftUI

enum CardStatus: String {
    case upcoming = "Upcoming"
    case completed = "Completed"
    case empty = ""
    
    var backgroundColor: Color {
        switch self {
        case .upcoming: return Color(hex: "F3AA18")
        case .completed: return Color(hex: "34C759")
        case .empty: return .clear
        }
    }
}

struct InfoCardData {
    let image: Image
    let heading: String
    let subheading: String
    let activeQueueCount: String?
    let detail1: (label: String, value: String)?
    let detail2: (label: String, value: String)?
    let price: String?
    var isPriceButtonVisble: Bool?
    
    var status: CardStatus = .empty

    let availableDates: [DoctorAvailability]?
    let maxPatientsPerDay: Int?
    
    init(
        image: Image,
        heading: String,
        subheading: String,
        activeQueueCount: String? = nil,
        detail1: (label: String, value: String)? = nil,
        detail2: (label: String, value: String)? = nil,
        price: String? = nil,
        availableDates: [DoctorAvailability]? = nil,
        maxPatientsPerDay: Int? = nil,
        isPriceButtonVisble: Bool? = true,
        status: CardStatus = .empty
    ) {
        self.image = image
        self.heading = heading
        self.subheading = subheading
        self.activeQueueCount = activeQueueCount
        self.detail1 = detail1
        self.detail2 = detail2
        self.price = price
        self.availableDates = availableDates
        self.maxPatientsPerDay = maxPatientsPerDay
        self.isPriceButtonVisble = isPriceButtonVisble
        self.status = status
    }
}

struct InfoCard: View {
    let data: InfoCardData
    var onPriceTap: (() -> Void)? = nil
    
    var body: some View {
        HStack(alignment: .top, spacing: 20) {
            data.image
                .resizable()
                .scaledToFill()
                .frame(width: 85, height: 85)
                .clipShape(Circle())
                .background(Color.gray.opacity(0.3))
                .clipShape(Circle())
            
            VStack(alignment: .leading, spacing: 8) {
     
                HStack(alignment: .top) {
                    VStack(alignment: .leading, spacing: 2) {
                        Text(data.heading)
                            .font(.system(size: 16, weight: .semibold))
                            .foregroundColor(AppColors.dark)
                        
                        Text(data.subheading)
                            .font(.system(size: 12))
                            .foregroundColor(AppColors.placeholder)
                    }
                    
                    Spacer()
                    
                   
                    if data.status != .empty {
                        Text(data.status.rawValue)
                            .font(.system(size: 12, weight: .medium))
                            .foregroundColor(.white)
                            .padding(.horizontal, 12)
                            .padding(.vertical, 6)
                            .background(data.status.backgroundColor)
                            .clipShape(RoundedRectangle(cornerRadius: 10))
                    }
                }
                

                VStack(alignment: .leading, spacing: 4) {
                    if let queue = data.activeQueueCount {
                        Text(queue)
                            .font(.system(size: 14, weight: .medium))
                            .foregroundColor(AppColors.primary)
                            .padding(.top, 4)
                    }
                    
                    if let detail1 = data.detail1 {
                        HStack(spacing: 4) {
                            Text(detail1.label).foregroundColor(AppColors.text)
                            Text(detail1.value).foregroundColor(AppColors.textdark)
                        }
                        .font(.system(size: 12))
                    }
                    
                    if let detail2 = data.detail2 {
                        HStack(spacing: 4) {
                            Text(detail2.label).foregroundColor(AppColors.text)
                            Text(detail2.value).foregroundColor(AppColors.textdark)
                        }
                        .font(.system(size: 12))
                    }

                    if let price = data.price, data.isPriceButtonVisble == true {
                        Button(action: { onPriceTap?() }) {
                            Text(price)
                                .font(.system(size: 13, weight: .semibold))
                                .foregroundColor(.white)
                                .padding(.horizontal, 12)
                                .padding(.vertical, 6)
                                .background(AppColors.primary)
                                .clipShape(Capsule())
                        }
                        .padding(.top, 6)
                        .buttonStyle(.plain)
                    }
                }
            }
        }
        .padding(15)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(
            RoundedRectangle(cornerRadius: 20)
                .fill(AppColors.lightBakcground)
        )
        .overlay(
            RoundedRectangle(cornerRadius: 20)
                .stroke(AppColors.border, lineWidth: 2)
        )
    }
}
#Preview {
    VStack(spacing: 20) {

        InfoCard(data: InfoCardData(
            image: Image(systemName: "person.fill"),
            heading: "Dr. Marcus Horizon",
            subheading: "Cardiologist",
            status: .upcoming
        ))
        
 
        InfoCard(data: InfoCardData(
            image: Image(systemName: "person.fill"),
            heading: "Dr. Marcus Horizon",
            subheading: "Cardiologist",
            status: .completed
        ))
        
 
        InfoCard(data: InfoCardData(
            image: Image(systemName: "person.fill"),
            heading: "Dr. Marcus Horizon",
            subheading: "Cardiologist"
        ))
    }
    .padding()
}
