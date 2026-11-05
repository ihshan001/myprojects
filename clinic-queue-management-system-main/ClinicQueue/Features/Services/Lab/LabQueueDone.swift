//
//  LabQueueDone.swift
//  ClinicQueue
//
//  Created by Keshana Liyanaarachchi on 2026-03-13.
//



import SwiftUI
import MapKit

struct LabQueueDone: View {
    let steps: [ClinicStep]
    @State private var navigateToCompleted = false

    var body: some View {
        ScrollView {
            QueueDone()
            
            VStack(alignment: .leading, spacing: 0) {
                ForEach(Array(steps.enumerated()), id: \.element.id) { index, step in
                    ReadyStepRow(
                        stepNumber: index + 1,
                        step: step,
                        isLast: index == steps.count - 1
                    )
                }

                HStack {
                    Spacer()

                    PrimaryButton(title: "Continue", maxWidth: 360) {
                        navigateToCompleted = true
                    }

                    Spacer()
                }
            }
            .padding(.horizontal, 30)
            .padding(.top, 24)
            .padding(.bottom, 40)
        }
        .navigationBarTitleDisplayMode(.inline)

   
        .navigationDestination(isPresented: $navigateToCompleted) {
            ServicesView()
        }

        .ignoresSafeArea()
    }
}

private struct ReadyStepRow: View {
    let stepNumber: Int
    let step: ClinicStep
    let isLast: Bool

    var body: some View {
        HStack(alignment: .top, spacing: 16) {

            VStack(spacing: 0) {
                ZStack {
                    Circle()
                        .fill(Color.green)
                        .frame(width: 38, height: 38)

                    Circle()
                        .fill(Color.white)
                        .frame(width: 14, height: 14)
                }

                DashedLineReady()
                    .stroke(
                        Color.green,
                        style: StrokeStyle(lineWidth: 2, dash: [6, 5])
                    )
                    .frame(width: 2)
                    .frame(maxHeight: .infinity)
                    .padding(.vertical, 4)
            }
            .frame(width: 38)

            VStack(alignment: .leading, spacing: 16) {

                VStack(alignment: .leading, spacing: 6) {
                    Text("Step \(String(format: "%02d", stepNumber))")
                        .font(.system(size: 20, weight: .bold))

                    Text(step.location ?? "Lab Test")
                        .font(.system(size: 14))
                        .foregroundColor(.secondary)

                    Text("completed")
                        .font(.system(size: 12, weight: .semibold))
                        .foregroundColor(.white)
                        .padding(.horizontal, 14)
                        .padding(.vertical, 5)
                        .background( Color.green)
                        .cornerRadius(20)
                }
                .padding(.top, 6)

                Text("Services")
                    .font(.system(size: 17, weight: .bold))

                ReadyServiceCard(step: step)

                Text("Location")
                    .font(.system(size: 17, weight: .bold))
                    .padding(.top, 4)

                LabDirectionMiniMap(targetRoomName: step.location ?? "Laboratory")
                    .frame(height: 200)
                    .padding(.bottom, 24)
            }
        }
    }
}

private struct ReadyServiceCard: View {
    let step: ClinicStep

    var body: some View {
        HStack(spacing: 14) {

            Image(TestDataset.imageName(for: step.name))
                .resizable()
                .scaledToFill()
                .frame(width: 72, height: 72)
                .clipShape(RoundedRectangle(cornerRadius: 12))
                .background(Color.gray.opacity(0.1))
                .cornerRadius(12)

            VStack(alignment: .leading, spacing: 4) {

                Text(step.name)
                    .font(.system(size: 15, weight: .semibold))
                    .lineLimit(2)

                Text("Your turn is ready!")
                    .font(.system(size: 13, weight: .medium))
                    .foregroundColor(AppColors.pink)

                Text("Estimated wait: \(step.estimatedWait ?? "~25 min")")
                    .font(.system(size: 12))
                    .foregroundColor(.secondary)

                Text("Location: \(step.location ?? "Lab Wing")")
                    .font(.system(size: 12))
                    .foregroundColor(.secondary)
                    .lineLimit(1)
            }

            Spacer()
        }
        .padding(12)
        .background(Color(.systemBackground))
        .cornerRadius(16)
        .shadow(color: .black.opacity(0.07), radius: 6, x: 0, y: 2)
    }
}

private struct ReadyMapView: View {

    @State private var region = MKCoordinateRegion(
        center: CLLocationCoordinate2D(
            latitude: 6.9271,
            longitude: 79.8612
        ),
        span: MKCoordinateSpan(
            latitudeDelta: 0.05,
            longitudeDelta: 0.05
        )
    )

    var body: some View {
        Map(coordinateRegion: $region)
            .disabled(true)
    }
}

private struct DashedLineReady: Shape {
    func path(in rect: CGRect) -> Path {
        var path = Path()

        path.move(to: CGPoint(x: rect.midX, y: rect.minY))
        path.addLine(to: CGPoint(x: rect.midX, y: rect.maxY))

        return path
    }
}

#Preview {
    NavigationStack {
        LabQueueDone(
            steps: [
                ClinicStep(
                    type: .imaging,
                    name: "Complete Blood Count",
                    description: "Blood Test at Lab 02",
                    estimatedWait: "~25 min",
                    price: 35,
                    location: "Room 02 – Consultation Wing",
                    requirements: []
                ),
                ClinicStep(
                    type: .imaging,
                    name: "ESR",
                    description: "Blood Test at Lab 02",
                    estimatedWait: "~25 min",
                    price: 20,
                    location: "Room 02 – Consultation Wing",
                    requirements: []
                )
            ]
        )
    }
}
