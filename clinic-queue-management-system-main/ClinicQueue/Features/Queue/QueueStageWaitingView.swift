//
//  QueueStageWaitingView.swift
//  ClinicQueue
//
//  Created by dilshan fernando on 2026-03-08.
//

import SwiftUI

import SwiftUI

struct QueueStageWaitingView: View {

    @EnvironmentObject var sessionManager: SessionManager

    private var doctorStep: ClinicStep? {
        sessionManager.currentClinicVisit?.doctorStep
    }

    private var queueNumber: String {
        doctorStep?.queueNumber ?? "--"
    }

    private var estimatedWait: String {
        doctorStep?.estimatedWait ?? "--"
    }

    private var nowServing: String {
        "05"
    }

    private var doctorInfo: InfoCardData? {
        guard let step = doctorStep else { return nil }

        return InfoCardData(
            image: Image("DoctorPlaceholder"),
            heading: step.name ?? "Doctor",
            subheading: step.specialty ?? "",
            price: nil,
            isPriceButtonVisble: false
        )
    }

    var body: some View {
        ScrollView {

            VStack(spacing: 32) {
                
                QueueBanner()


                Text("Services")
                    .font(.system(size: 20, weight: .bold))
                    .padding(.top, Spacing.section)

                if let doctor = doctorInfo {
                    InfoCard(data: doctor)
                        .padding(.horizontal)
                }
                
                Text("Location")
                    .font(.system(size: 20, weight: .bold))
                    .padding(.top, Spacing.section)

            }
        }
    }
}


#Preview {
    QueueStageWaitingView()
        .environmentObject(SessionManager())
}
